<?php
declare(strict_types=1);

namespace App\Services\Payments;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\ActivityService;
use App\Services\EntityHooks;
use App\Services\NotificationService;
use App\Services\RecordService;

/**
 * Online invoice payments. Money is recorded only from a verified gateway confirmation (signed return or webhook),
 * exactly once per gateway payment, for the amount and currency the gateway reports and we originally requested.
 */
final class PaymentService
{
    public const GATEWAYS = ['razorpay' => Razorpay::class, 'stripe' => Stripe::class, 'paypal' => PayPal::class];
    public const PAYABLE = ['Sent', 'Partially Paid', 'Overdue'];

    public static function config(string $key): array
    {
        $g = fn (string $k) => (string) setting('pay.' . $key . '.' . $k, '');
        return match ($key) {
            'razorpay' => ['enabled' => (bool) setting('pay.razorpay.enabled', false), 'key_id' => $g('key_id'), 'key_secret' => $g('key_secret'), 'webhook_secret' => $g('webhook_secret')],
            'stripe' => ['enabled' => (bool) setting('pay.stripe.enabled', false), 'secret_key' => $g('secret_key'), 'webhook_secret' => $g('webhook_secret')],
            'paypal' => ['enabled' => (bool) setting('pay.paypal.enabled', false), 'client_id' => $g('client_id'), 'client_secret' => $g('client_secret'), 'webhook_id' => $g('webhook_id'), 'sandbox' => (bool) setting('pay.paypal.sandbox', false)],
            default => [],
        };
    }

    public static function gateway(string $key, bool $requireEnabled = true): ?Gateway
    {
        $class = self::GATEWAYS[$key] ?? null;
        if (!$class) {
            return null;
        }
        $cfg = self::config($key);
        $ready = match ($key) {
            'razorpay' => $cfg['key_id'] !== '' && $cfg['key_secret'] !== '',
            'stripe' => $cfg['secret_key'] !== '',
            'paypal' => $cfg['client_id'] !== '' && $cfg['client_secret'] !== '',
        };
        if (!$ready || ($requireEnabled && !$cfg['enabled'])) {
            return null;
        }
        return new $class($cfg);
    }

    /** @return Gateway[] */
    public static function enabled(): array
    {
        return array_values(array_filter(array_map(fn ($k) => self::gateway($k), array_keys(self::GATEWAYS))));
    }

    /** Starts a checkout for the invoice's outstanding balance and returns the gateway redirect URL. */
    public static function start(array $invoice, string $gatewayKey, string $source): string
    {
        $gw = self::gateway($gatewayKey);
        if (!$gw) {
            throw new HttpException(422, 'This payment method is not available.');
        }
        if (!in_array($invoice['status'], self::PAYABLE, true) || (float) $invoice['balance'] <= 0) {
            throw new HttpException(422, 'This invoice has nothing left to pay.');
        }
        $db = App::db();
        $id = $db->insert('payment_attempts', [
            'company_id' => (int) $invoice['company_id'], 'invoice_id' => (int) $invoice['id'], 'gateway' => $gatewayKey,
            'amount' => round((float) $invoice['balance'], 2), 'currency' => strtoupper((string) $invoice['currency']),
            'status' => 'created', 'source' => $source, 'created_at' => DB::now(), 'updated_at' => DB::now(),
        ]);
        $attempt = $db->find('payment_attempts', $id);
        $return = full_url('/pay/return/' . $gatewayKey . '/' . $id);
        $cancel = $source === 'portal' ? full_url('/portal/invoices/' . $invoice['id']) : full_url('/p/invoice/' . $invoice['public_token']);
        try {
            $co = $gw->createCheckout($attempt, $invoice, $return, $cancel);
        } catch (\Throwable $e) {
            $db->update('payment_attempts', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => DB::now()], ['id' => $id]);
            \App\Core\ErrorHandler::log('Payment start failed: ' . $e->getMessage(), 'payments');
            throw new HttpException(502, 'The payment provider could not be reached. Please try again in a moment.');
        }
        $db->update('payment_attempts', ['gateway_order_id' => $co['order_id'], 'status' => 'pending', 'updated_at' => DB::now()], ['id' => $id]);
        return $co['redirect'];
    }

    /**
     * Records a verified payment for an attempt. Idempotent: safe to call from both return and webhook.
     * @return array{ok:bool, message:string}
     */
    public static function complete(int $attemptId, array $result): array
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $attemptId, $result) {
            $a = $db->one('SELECT * FROM payment_attempts WHERE id = ?' . $db->forUpdate(), [$attemptId]);
            if (!$a) {
                return ['ok' => false, 'message' => 'Unknown payment.'];
            }
            if ($a['status'] === 'paid') {
                return ['ok' => true, 'message' => 'Payment already recorded.'];
            }
            if ((string) $result['order_id'] !== (string) $a['gateway_order_id']) {
                return ['ok' => false, 'message' => 'Payment reference mismatch.'];
            }
            // Never trust a different amount or currency than the one we asked the gateway to collect.
            if (abs((float) $result['amount'] - (float) $a['amount']) > 0.009 || strtoupper((string) $result['currency']) !== strtoupper((string) $a['currency'])) {
                $db->update('payment_attempts', ['status' => 'review', 'error' => 'Amount/currency mismatch: received ' . $result['amount'] . ' ' . $result['currency'], 'updated_at' => DB::now()], ['id' => $attemptId]);
                \App\Core\ErrorHandler::log("Payment attempt $attemptId amount mismatch", 'payments');
                return ['ok' => false, 'message' => 'The payment needs manual review. We have been notified.'];
            }
            $existing = $db->value('SELECT id FROM payments WHERE gateway = ? AND gateway_payment_id = ? AND deleted_at IS NULL', [$a['gateway'], $result['payment_id']]);
            if ($existing) {
                $db->update('payment_attempts', ['status' => 'paid', 'payment_id' => (int) $existing, 'gateway_payment_id' => $result['payment_id'], 'updated_at' => DB::now()], ['id' => $attemptId]);
                return ['ok' => true, 'message' => 'Payment already recorded.'];
            }
            $inv = $db->find('invoices', (int) $a['invoice_id']);
            $owner = (int) ($inv['assigned_to'] ?: $inv['created_by']);
            $label = (self::GATEWAYS[$a['gateway']] ?? '') ? (new (self::GATEWAYS[$a['gateway']])([]))->label() : $a['gateway'];
            $paymentId = Auth::actingAs($owner, (int) $a['company_id'], function () use ($a, $result, $label, $db) {
                EntityHooks::$gatewayPayment = true; // money already collected: record it even if it exceeds the balance
                try {
                    $p = RecordService::for('payments')->create([
                        'invoice_id' => (string) $a['invoice_id'], 'amount' => (string) $a['amount'], 'paid_on' => date('Y-m-d'),
                        'method' => $label, 'reference' => mb_substr((string) $result['payment_id'], 0, 100), 'notes' => 'Paid online via ' . $label . '.',
                    ], ['skip_permission' => true, 'system' => true]);
                } finally {
                    EntityHooks::$gatewayPayment = false;
                }
                $db->update('payments', ['gateway' => $a['gateway'], 'gateway_payment_id' => $result['payment_id']], ['id' => (int) $p['id']]);
                ActivityService::log('invoices', (int) $a['invoice_id'], 'system', 'Paid online via ' . $label . ' (' . money((float) $a['amount'], $a['currency']) . ').');
                return (int) $p['id'];
            });
            $db->update('payment_attempts', ['status' => 'paid', 'payment_id' => $paymentId, 'gateway_payment_id' => $result['payment_id'], 'updated_at' => DB::now()], ['id' => $attemptId]);
            if ($owner) {
                NotificationService::notify($owner, 'payment.online', 'Online payment received', $inv['number'] . ' · ' . money((float) $a['amount'], $a['currency']) . ' via ' . $label, '/r/invoices/' . $inv['id'], (int) $a['company_id']);
            }
            return ['ok' => true, 'message' => 'Thank you! Your payment has been received.'];
        });
    }

    /** Webhook entry: verify, de-duplicate the event, then complete the matching attempt. */
    public static function webhook(string $gatewayKey, int $companyId, string $raw, array $headers): array
    {
        return Auth::actingAs(0, $companyId, function () use ($gatewayKey, $raw, $headers) {
            $gw = self::gateway($gatewayKey, false);
            if (!$gw) {
                throw new HttpException(404);
            }
            $ev = $gw->parseWebhook($raw, $headers); // throws on bad signature
            $db = App::db();
            if ($ev['event_id'] === '') {
                throw new HttpException(400, 'Missing event id.');
            }
            try {
                $db->insert('gateway_events', ['gateway' => $gatewayKey, 'event_id' => mb_substr($ev['event_id'], 0, 150), 'type' => mb_substr($ev['type'], 0, 80), 'received_at' => DB::now()]);
            } catch (\PDOException) {
                return ['ok' => true, 'message' => 'duplicate event ignored'];
            }
            if (!$ev['paid'] || !$ev['order_id']) {
                return ['ok' => true, 'message' => 'event ignored'];
            }
            $attempt = $db->one('SELECT * FROM payment_attempts WHERE gateway = ? AND gateway_order_id = ? AND company_id = ?', [$gatewayKey, $ev['order_id'], Auth::companyId()]);
            if (!$attempt) {
                return ['ok' => true, 'message' => 'no matching payment'];
            }
            return self::complete((int) $attempt['id'], ['order_id' => $ev['order_id'], 'payment_id' => (string) ($ev['payment_id'] ?: $ev['order_id']), 'amount' => $ev['amount'], 'currency' => $ev['currency']]);
        }) ?? ['ok' => false, 'message' => 'Company not found'];
    }
}

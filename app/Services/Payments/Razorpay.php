<?php
declare(strict_types=1);

namespace App\Services\Payments;

defined('NX_ROOT') || exit;

/** Razorpay Payment Links (hosted checkout — no third-party script on our pages). */
final class Razorpay extends Gateway
{
    private const API = 'https://api.razorpay.com/v1';

    public function key(): string { return 'razorpay'; }

    public function label(): string { return 'Razorpay'; }

    private function auth(): array
    {
        return ['Authorization: Basic ' . base64_encode($this->cfg['key_id'] . ':' . $this->cfg['key_secret']), 'Content-Type: application/json'];
    }

    public function createCheckout(array $attempt, array $invoice, string $returnUrl, string $cancelUrl): array
    {
        $body = [
            'amount' => self::minor((float) $attempt['amount'], (string) $attempt['currency']),
            'currency' => $attempt['currency'],
            'description' => 'Invoice ' . $invoice['number'],
            'reference_id' => 'NX' . $attempt['id'],
            'callback_url' => $returnUrl,
            'callback_method' => 'get',
            'notes' => ['attempt_id' => (string) $attempt['id'], 'invoice' => (string) $invoice['number']],
        ];
        $res = $this->http('POST', self::API . '/payment_links', $this->auth(), (string) json_encode($body));
        if (empty($res['short_url']) || empty($res['id'])) {
            throw new \RuntimeException('Razorpay did not return a payment link.');
        }
        return ['redirect' => (string) $res['short_url'], 'order_id' => (string) $res['id']];
    }

    public function confirmReturn(array $attempt, array $q): ?array
    {
        $linkId = (string) ($q['razorpay_payment_link_id'] ?? '');
        $ref = (string) ($q['razorpay_payment_link_reference_id'] ?? '');
        $status = (string) ($q['razorpay_payment_link_status'] ?? '');
        $payId = (string) ($q['razorpay_payment_id'] ?? '');
        $sig = (string) ($q['razorpay_signature'] ?? '');
        $expected = hash_hmac('sha256', $linkId . '|' . $ref . '|' . $status . '|' . $payId, (string) $this->cfg['key_secret']);
        if ($sig === '' || !hash_equals($expected, $sig) || $linkId !== $attempt['gateway_order_id'] || $status !== 'paid') {
            return null;
        }
        // Server-side confirmation of the amount actually paid.
        $link = $this->http('GET', self::API . '/payment_links/' . rawurlencode($linkId), $this->auth());
        if (($link['status'] ?? '') !== 'paid') {
            return null;
        }
        return ['order_id' => $linkId, 'payment_id' => $payId, 'amount' => self::fromMinor((int) ($link['amount_paid'] ?? 0), (string) ($link['currency'] ?? $attempt['currency'])), 'currency' => (string) ($link['currency'] ?? $attempt['currency'])];
    }

    public function parseWebhook(string $raw, array $headers): array
    {
        $sig = (string) ($headers['x-razorpay-signature'] ?? '');
        $secret = (string) ($this->cfg['webhook_secret'] ?? '');
        if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
            throw new \RuntimeException('Invalid Razorpay webhook signature.');
        }
        $e = json_decode($raw, true) ?: [];
        $event = (string) ($e['event'] ?? '');
        $link = $e['payload']['payment_link']['entity'] ?? [];
        $pay = $e['payload']['payment']['entity'] ?? [];
        return [
            'event_id' => (string) ($headers['x-razorpay-event-id'] ?? hash('sha256', $raw)),
            'type' => $event,
            'paid' => $event === 'payment_link.paid',
            'order_id' => isset($link['id']) ? (string) $link['id'] : null,
            'payment_id' => isset($pay['id']) ? (string) $pay['id'] : null,
            'amount' => self::fromMinor((int) ($pay['amount'] ?? $link['amount_paid'] ?? 0), (string) ($pay['currency'] ?? $link['currency'] ?? '')),
            'currency' => (string) ($pay['currency'] ?? $link['currency'] ?? ''),
        ];
    }
}

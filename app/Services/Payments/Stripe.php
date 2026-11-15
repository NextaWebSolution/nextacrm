<?php
declare(strict_types=1);

namespace App\Services\Payments;

defined('NX_ROOT') || exit;

/** Stripe Checkout Sessions (hosted). */
final class Stripe extends Gateway
{
    private const API = 'https://api.stripe.com/v1';
    private const TOLERANCE = 300;

    public function key(): string { return 'stripe'; }

    public function label(): string { return 'Stripe'; }

    private function auth(): array
    {
        return ['Authorization: Bearer ' . $this->cfg['secret_key'], 'Content-Type: application/x-www-form-urlencoded', 'Stripe-Version: 2024-06-20'];
    }

    public function createCheckout(array $attempt, array $invoice, string $returnUrl, string $cancelUrl): array
    {
        $form = http_build_query([
            'mode' => 'payment',
            'client_reference_id' => 'NX' . $attempt['id'],
            'metadata' => ['attempt_id' => (string) $attempt['id'], 'invoice' => (string) $invoice['number']],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => strtolower($attempt['currency']), 'unit_amount' => self::minor((float) $attempt['amount'], (string) $attempt['currency']), 'product_data' => ['name' => 'Invoice ' . $invoice['number']]],
            ]],
            'success_url' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ], '', '&', PHP_QUERY_RFC3986);
        $form = str_replace('%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $form);
        $res = $this->http('POST', self::API . '/checkout/sessions', $this->auth(), $form);
        if (empty($res['url']) || empty($res['id'])) {
            throw new \RuntimeException('Stripe did not return a checkout URL.');
        }
        return ['redirect' => (string) $res['url'], 'order_id' => (string) $res['id']];
    }

    public function confirmReturn(array $attempt, array $q): ?array
    {
        $sid = (string) ($q['session_id'] ?? '');
        if ($sid === '' || $sid !== $attempt['gateway_order_id']) {
            return null;
        }
        $s = $this->http('GET', self::API . '/checkout/sessions/' . rawurlencode($sid), $this->auth());
        if (($s['payment_status'] ?? '') !== 'paid') {
            return null;
        }
        return ['order_id' => $sid, 'payment_id' => (string) ($s['payment_intent'] ?? $sid), 'amount' => self::fromMinor((int) ($s['amount_total'] ?? 0), (string) ($s['currency'] ?? '')), 'currency' => strtoupper((string) ($s['currency'] ?? ''))];
    }

    public function parseWebhook(string $raw, array $headers): array
    {
        $secret = (string) ($this->cfg['webhook_secret'] ?? '');
        $header = (string) ($headers['stripe-signature'] ?? '');
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') { $t = (int) $v; }
            if ($k === 'v1') { $sigs[] = $v; }
        }
        $expected = hash_hmac('sha256', $t . '.' . $raw, $secret);
        $ok = $secret !== '' && $t && abs(time() - $t) <= self::TOLERANCE;
        $ok = $ok && (bool) array_filter($sigs, fn ($s) => hash_equals($expected, $s));
        if (!$ok) {
            throw new \RuntimeException('Invalid or expired Stripe webhook signature.');
        }
        $e = json_decode($raw, true) ?: [];
        $o = $e['data']['object'] ?? [];
        $type = (string) ($e['type'] ?? '');
        return [
            'event_id' => (string) ($e['id'] ?? ''),
            'type' => $type,
            'paid' => in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && ($o['payment_status'] ?? '') === 'paid',
            'order_id' => isset($o['id']) ? (string) $o['id'] : null,
            'payment_id' => isset($o['payment_intent']) ? (string) $o['payment_intent'] : null,
            'amount' => self::fromMinor((int) ($o['amount_total'] ?? 0), (string) ($o['currency'] ?? '')),
            'currency' => strtoupper((string) ($o['currency'] ?? '')),
        ];
    }
}

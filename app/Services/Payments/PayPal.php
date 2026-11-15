<?php
declare(strict_types=1);

namespace App\Services\Payments;

defined('NX_ROOT') || exit;

/** PayPal Orders v2 (approve on PayPal, capture server-side on return). */
final class PayPal extends Gateway
{
    public function key(): string { return 'paypal'; }

    public function label(): string { return 'PayPal'; }

    private function base(): string
    {
        return !empty($this->cfg['sandbox']) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function token(): string
    {
        $res = $this->http('POST', $this->base() . '/v1/oauth2/token', [
            'Authorization: Basic ' . base64_encode($this->cfg['client_id'] . ':' . $this->cfg['client_secret']),
            'Content-Type: application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');
        if (empty($res['access_token'])) {
            throw new \RuntimeException('PayPal authentication failed.');
        }
        return (string) $res['access_token'];
    }

    private function headers(): array
    {
        return ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json'];
    }

    public function createCheckout(array $attempt, array $invoice, string $returnUrl, string $cancelUrl): array
    {
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'NX' . $attempt['id'],
                'custom_id' => (string) $attempt['id'],
                'description' => 'Invoice ' . $invoice['number'],
                'amount' => ['currency_code' => $attempt['currency'], 'value' => number_format((float) $attempt['amount'], in_array(strtoupper((string) $attempt['currency']), ['HUF', 'JPY', 'TWD'], true) ? 0 : 2, '.', '')],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING']]],
        ];
        $res = $this->http('POST', $this->base() . '/v2/checkout/orders', array_merge($this->headers(), ['PayPal-Request-Id: nx-' . $attempt['id']]), (string) json_encode($body));
        $approve = '';
        foreach ($res['links'] ?? [] as $l) {
            if (in_array($l['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $approve = (string) $l['href'];
            }
        }
        if ($approve === '' || empty($res['id'])) {
            throw new \RuntimeException('PayPal did not return an approval link.');
        }
        return ['redirect' => $approve, 'order_id' => (string) $res['id']];
    }

    public function confirmReturn(array $attempt, array $q): ?array
    {
        $orderId = (string) ($q['token'] ?? '');
        if ($orderId === '' || $orderId !== $attempt['gateway_order_id']) {
            return null;
        }
        $headers = $this->headers();
        $headers[] = 'PayPal-Request-Id: nx-capture-' . $attempt['id'];
        $res = $this->http('POST', $this->base() . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', $headers, '{}');
        $cap = $res['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (($res['status'] ?? '') !== 'COMPLETED' || !$cap || ($cap['status'] ?? '') !== 'COMPLETED') {
            return null;
        }
        return ['order_id' => $orderId, 'payment_id' => (string) $cap['id'], 'amount' => (float) ($cap['amount']['value'] ?? 0), 'currency' => (string) ($cap['amount']['currency_code'] ?? '')];
    }

    public function parseWebhook(string $raw, array $headers): array
    {
        $event = json_decode($raw, true);
        if (!is_array($event) || empty($this->cfg['webhook_id'])) {
            throw new \RuntimeException('Invalid PayPal webhook.');
        }
        // PayPal signatures are verified by PayPal's own API (certificate chain handled server-side by PayPal).
        $verify = $this->http('POST', $this->base() . '/v1/notifications/verify-webhook-signature', $this->headers(), (string) json_encode([
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $this->cfg['webhook_id'],
            'webhook_event' => $event,
        ]));
        if (($verify['verification_status'] ?? '') !== 'SUCCESS') {
            throw new \RuntimeException('PayPal webhook signature could not be verified.');
        }
        $r = $event['resource'] ?? [];
        $type = (string) ($event['event_type'] ?? '');
        return [
            'event_id' => (string) ($event['id'] ?? ''),
            'type' => $type,
            'paid' => $type === 'PAYMENT.CAPTURE.COMPLETED',
            'order_id' => isset($r['supplementary_data']['related_ids']['order_id']) ? (string) $r['supplementary_data']['related_ids']['order_id'] : null,
            'payment_id' => isset($r['id']) ? (string) $r['id'] : null,
            'amount' => (float) ($r['amount']['value'] ?? 0),
            'currency' => (string) ($r['amount']['currency_code'] ?? ''),
        ];
    }
}

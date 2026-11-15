<?php
declare(strict_types=1);

namespace App\Services\Payments;

defined('NX_ROOT') || exit;

use App\Core\HttpClient;

/**
 * Payment gateway adapter. Amounts are passed in major units (e.g. rupees) and converted per gateway.
 * Every result that marks money as received must come from a server-to-server check or a verified signature —
 * never from browser-supplied parameters alone.
 */
abstract class Gateway
{
    public function __construct(protected array $cfg) {}

    abstract public function key(): string;

    abstract public function label(): string;

    /** @return array{redirect:string, order_id:string} */
    abstract public function createCheckout(array $attempt, array $invoice, string $returnUrl, string $cancelUrl): array;

    /**
     * Confirms a browser return. Returns a normalised result or null if not (yet) paid.
     * @return array{order_id:string, payment_id:string, amount:float, currency:string}|null
     */
    abstract public function confirmReturn(array $attempt, array $query): ?array;

    /**
     * Verifies and parses a webhook. Throws on an invalid signature.
     * @return array{event_id:string, type:string, paid:bool, order_id:?string, payment_id:?string, amount:float, currency:string}
     */
    abstract public function parseWebhook(string $raw, array $headers): array;

    /** Currencies whose smallest unit is not 1/100 (ISO 4217 exponent). */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    public static function exponent(string $currency): int
    {
        $c = strtoupper($currency);
        return in_array($c, self::ZERO_DECIMAL, true) ? 0 : (in_array($c, self::THREE_DECIMAL, true) ? 3 : 2);
    }

    /** Amount in the gateway's smallest currency unit (paise, cents, yen…). */
    public static function minor(float $amount, string $currency = 'INR'): int
    {
        return (int) round($amount * (10 ** self::exponent($currency)));
    }

    public static function fromMinor(int|float|string $minor, string $currency = 'INR'): float
    {
        return round(((float) $minor) / (10 ** self::exponent($currency)), 3);
    }

    protected function http(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $res = (new HttpClient(false, 25, 1_048_576))->request($method, $url, $headers, $body);
        if ($res['error']) {
            throw new \RuntimeException($this->label() . ': ' . $res['error']);
        }
        $data = json_decode((string) $res['body'], true);
        if ($res['status'] >= 400) {
            $msg = $data['error']['description'] ?? $data['error']['message'] ?? $data['message'] ?? $data['error_description'] ?? ('HTTP ' . $res['status']);
            throw new \RuntimeException($this->label() . ': ' . (is_string($msg) ? $msg : 'request failed'));
        }
        return is_array($data) ? $data : [];
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpClient;
use App\Core\HttpException;

/**
 * WhatsApp messaging through a provider: Meta WhatsApp Cloud API, Twilio, or Gupshup.
 * Note: outside the 24-hour customer-service window WhatsApp only delivers approved templates — use a template name.
 */
final class WhatsAppService
{
    public const PROVIDERS = ['meta' => 'Meta WhatsApp Cloud API', 'twilio' => 'Twilio', 'gupshup' => 'Gupshup'];

    public static function provider(): string
    {
        $p = (string) setting('wa.provider', '');
        return isset(self::PROVIDERS[$p]) ? $p : '';
    }

    public static function enabled(): bool
    {
        return self::provider() !== '';
    }

    /** E.164 without the plus, using the default country code for local numbers. */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $cc = preg_replace('/\D+/', '', (string) setting('wa.default_cc', '91')) ?: '91';
        if (str_starts_with(trim($phone), '+') || str_starts_with(trim($phone), '00')) {
            $digits = ltrim($digits, '0');
        } elseif (strlen($digits) <= 10) {
            $digits = $cc . ltrim($digits, '0');
        }
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw new HttpException(422, 'Enter a valid mobile number with country code.');
        }
        return $digits;
    }

    /** Best phone number on a record for WhatsApp. */
    public static function phoneFor(array $rec): string
    {
        foreach (['whatsapp', 'mobile', 'phone', 'requester_phone'] as $k) {
            if (!empty($rec[$k])) {
                return (string) $rec[$k];
            }
        }
        if (!empty($rec['contact_id'])) {
            $c = App::db()->one('SELECT mobile, phone FROM contacts WHERE id = ? AND company_id = ?', [(int) $rec['contact_id'], Auth::companyId()]);
            return (string) ($c['mobile'] ?? '') ?: (string) ($c['phone'] ?? '');
        }
        if (!empty($rec['customer_id'])) {
            return (string) App::db()->value('SELECT phone FROM customers WHERE id = ? AND company_id = ?', [(int) $rec['customer_id'], Auth::companyId()]);
        }
        return '';
    }

    /** Sends a message and logs it. Returns the provider message id. */
    public static function send(string $to, string $body, ?string $entity = null, ?int $recordId = null, ?string $template = null): string
    {
        $provider = self::provider();
        if ($provider === '') {
            throw new HttpException(422, 'WhatsApp is not set up. Choose a provider in Settings → WhatsApp.');
        }
        $num = self::normalize($to);
        $body = trim(mb_substr($body, 0, 4000));
        if ($body === '' && !$template) {
            throw new HttpException(422, 'Write a message.');
        }
        $db = App::db();
        $logId = $db->insert('whatsapp_messages', ['company_id' => Auth::companyId(), 'provider' => $provider, 'to_number' => $num, 'body' => $body, 'template' => $template, 'status' => 'queued', 'entity' => $entity, 'record_id' => $recordId, 'created_by' => Auth::id() ?: null, 'created_at' => DB::now()]);
        try {
            $id = match ($provider) {
                'meta' => self::sendMeta($num, $body, $template),
                'twilio' => self::sendTwilio($num, $body),
                'gupshup' => self::sendGupshup($num, $body),
            };
            $db->update('whatsapp_messages', ['status' => 'sent', 'provider_message_id' => mb_substr($id, 0, 150)], ['id' => $logId]);
        } catch (\Throwable $e) {
            $db->update('whatsapp_messages', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)], ['id' => $logId]);
            throw new HttpException(502, 'WhatsApp: ' . $e->getMessage());
        }
        if ($entity && $recordId) {
            ActivityService::log($entity, $recordId, 'whatsapp', $template ? "Template \"$template\": $body" : $body, 'WhatsApp to +' . $num);
        }
        return $id;
    }

    private static function call(string $url, array $headers, string $body): array
    {
        $res = (new HttpClient(false, 20))->request('POST', $url, $headers, $body);
        if ($res['error']) {
            throw new \RuntimeException($res['error']);
        }
        $data = json_decode((string) $res['body'], true) ?: [];
        if ($res['status'] >= 400) {
            $msg = $data['error']['message'] ?? $data['message'] ?? $data['errors'][0]['message'] ?? ('HTTP ' . $res['status']);
            throw new \RuntimeException(is_string($msg) ? $msg : 'request failed');
        }
        return $data;
    }

    private static function sendMeta(string $to, string $body, ?string $template): string
    {
        $phoneId = preg_replace('/\D/', '', (string) setting('wa.meta.phone_id', '')) ?? '';
        $token = (string) setting('wa.meta.token', '');
        if ($phoneId === '' || $token === '') {
            throw new \RuntimeException('Meta phone number ID and access token are required.');
        }
        $payload = $template
            ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $template, 'language' => ['code' => (string) setting('wa.meta.language', 'en')],
                'components' => $body !== '' ? [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => trim($p)], explode('|', $body))]] : []]]
            : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => $body]];
        $data = self::call('https://graph.facebook.com/v20.0/' . $phoneId . '/messages', ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], (string) json_encode($payload));
        return (string) ($data['messages'][0]['id'] ?? '');
    }

    private static function sendTwilio(string $to, string $body): string
    {
        $sid = preg_replace('/[^A-Za-z0-9]/', '', (string) setting('wa.twilio.sid', '')) ?? '';
        $token = (string) setting('wa.twilio.token', '');
        $from = preg_replace('/[^\d+]/', '', (string) setting('wa.twilio.from', '')) ?? '';
        if ($sid === '' || $token === '' || $from === '') {
            throw new \RuntimeException('Twilio account SID, auth token and WhatsApp sender are required.');
        }
        $data = self::call("https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json", ['Authorization: Basic ' . base64_encode("$sid:$token"), 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['From' => 'whatsapp:' . (str_starts_with($from, '+') ? $from : '+' . $from), 'To' => 'whatsapp:+' . $to, 'Body' => $body]));
        return (string) ($data['sid'] ?? '');
    }

    private static function sendGupshup(string $to, string $body): string
    {
        $key = (string) setting('wa.gupshup.api_key', '');
        $source = preg_replace('/\D/', '', (string) setting('wa.gupshup.source', '')) ?? '';
        $app = (string) setting('wa.gupshup.app', '');
        if ($key === '' || $source === '') {
            throw new \RuntimeException('Gupshup API key and source number are required.');
        }
        $data = self::call('https://api.gupshup.io/wa/api/v1/msg', ['apikey: ' . $key, 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['channel' => 'whatsapp', 'source' => $source, 'destination' => $to, 'src.name' => $app, 'message' => json_encode(['type' => 'text', 'text' => $body])]));
        return (string) ($data['messageId'] ?? '');
    }
}

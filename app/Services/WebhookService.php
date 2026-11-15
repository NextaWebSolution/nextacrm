<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpClient;
use App\Core\Settings;

/**
 * Outgoing webhooks: queued deliveries, HMAC-SHA256 signatures, exponential retry, auto-disable.
 */
final class WebhookService
{
    public const BACKOFF = [60, 300, 1800, 7200, 43200]; // seconds after attempt 1..5

    public static function events(): array
    {
        $out = [];
        foreach (EntityRegistry::all() as $key => $d) {
            foreach (['created', 'updated', 'deleted'] as $a) {
                $out[] = "$key.$a";
            }
        }
        $out[] = 'leads.converted';
        $out[] = 'invoices.paid';
        return $out;
    }

    public static function onEvent(string $event, array $p): void
    {
        if (empty($p['entity']) || empty($p['record']['company_id'])) {
            return;
        }
        $names = [];
        if (str_starts_with($event, 'record.')) {
            $names[] = $p['entity'] . '.' . substr($event, 7);
            if ($p['entity'] === 'invoices' && ($p['record']['status'] ?? '') === 'Paid' && ($p['old']['status'] ?? '') !== 'Paid') {
                $names[] = 'invoices.paid';
            }
        } elseif ($event === 'lead.converted') {
            $names[] = 'leads.converted';
        }
        if (!$names) {
            return;
        }
        $db = App::db();
        $hooks = $db->all('SELECT * FROM webhooks WHERE company_id = ? AND is_active = 1', [(int) $p['record']['company_id']]);
        $queued = false;
        foreach ($hooks as $h) {
            $subscribed = json_decode((string) $h['events'], true) ?: [];
            foreach ($names as $name) {
                if (in_array('*', $subscribed, true) || in_array($name, $subscribed, true)) {
                    $payload = json_encode([
                        'id' => bin2hex(random_bytes(8)),
                        'event' => $name,
                        'occurred_at' => gmdate('c'),
                        'data' => WorkflowService::publicRecord($p['entity'], $p['record']),
                        'changes' => $p['changes'] ?? [],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $db->insert('webhook_deliveries', ['webhook_id' => $h['id'], 'event' => $name, 'payload' => $payload, 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => DB::now(), 'created_at' => DB::now()]);
                    $queued = true;
                }
            }
        }
        if ($queued && (int) Settings::get('cron.last_run', 0) < time() - 900) {
            register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                self::processQueue(10);
            });
        }
    }

    public static function processQueue(int $limit = 50): int
    {
        $db = App::db();
        $rows = $db->all("SELECT d.*, w.url, w.secret, w.is_active, w.id AS hook_id FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE d.status = 'pending' AND d.next_attempt_at <= ? ORDER BY d.id LIMIT " . (int) $limit, [DB::now()]);
        $n = 0;
        foreach ($rows as $d) {
            if (!$d['is_active']) {
                $db->update('webhook_deliveries', ['status' => 'cancelled'], ['id' => $d['id']]);
                continue;
            }
            [$code, $body, $err] = self::deliver($d['url'], Crypto::decrypt($d['secret']), $d['event'], $d['payload']);
            $attempts = (int) $d['attempts'] + 1;
            if ($code >= 200 && $code < 300) {
                $db->update('webhook_deliveries', ['status' => 'success', 'attempts' => $attempts, 'response_code' => $code, 'response_body' => mb_substr($body, 0, 2000), 'delivered_at' => DB::now()], ['id' => $d['id']]);
                $db->update('webhooks', ['failure_count' => 0, 'last_status' => $code], ['id' => $d['hook_id']]);
                $n++;
            } else {
                $final = $attempts > count(self::BACKOFF);
                $db->update('webhook_deliveries', [
                    'status' => $final ? 'failed' : 'pending', 'attempts' => $attempts, 'response_code' => $code ?: null,
                    'response_body' => mb_substr($err ?: $body, 0, 2000),
                    'next_attempt_at' => $final ? null : gmdate('Y-m-d H:i:s', time() + self::BACKOFF[$attempts - 1]),
                ], ['id' => $d['id']]);
                $db->query('UPDATE webhooks SET failure_count = failure_count + 1, last_status = ? WHERE id = ?', [$code, $d['hook_id']]);
                $db->query('UPDATE webhooks SET is_active = 0 WHERE id = ? AND failure_count >= 50', [$d['hook_id']]);
            }
        }
        return $n;
    }

    /** @return array{0:int,1:string,2:?string} */
    public static function deliver(string $url, string $secret, string $event, string $payload): array
    {
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        $res = (new HttpClient())->request('POST', $url, [
            'Content-Type: application/json',
            'X-Nexta-Event: ' . $event,
            'X-Nexta-Timestamp: ' . $ts,
            'X-Nexta-Signature: sha256=' . $sig,
        ], $payload);
        return [$res['status'], $res['body'], $res['error']];
    }
}

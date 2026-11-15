<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class Audit
{
    public static function log(string $action, ?string $entity = null, ?int $recordId = null, ?array $changes = null, ?int $userId = null): void
    {
        try {
            $req = App::request();
            App::db()->insert('audit_logs', [
                'company_id' => Auth::check() ? Auth::companyId() : null,
                'user_id' => $userId ?? (Auth::id() ?: null),
                'action' => substr($action, 0, 64),
                'entity' => $entity,
                'record_id' => $recordId,
                'changes' => $changes !== null ? json_encode(self::redact($changes), JSON_UNESCAPED_UNICODE) : null,
                'ip' => $req->ip(),
                'user_agent' => $req->userAgent(),
                'created_at' => DB::now(),
            ]);
        } catch (\Throwable $e) {
            ErrorHandler::log('Audit failure: ' . $e->getMessage());
        }
    }

    private static function redact(array $changes): array
    {
        foreach ($changes as $k => $v) {
            if (is_string($k) && preg_match('/password|secret|token|private_key|recovery/i', $k)) {
                $changes[$k] = '***';
            } elseif (is_array($v)) {
                $changes[$k] = self::redact($v);
            }
        }
        return $changes;
    }

    /** Field-level diff between old and new records. */
    public static function diff(array $old, array $new, array $ignore = ['updated_at', 'updated_by']): array
    {
        $d = [];
        foreach ($new as $k => $v) {
            if (in_array($k, $ignore, true)) {
                continue;
            }
            $o = $old[$k] ?? null;
            if (preg_match('/password|secret|token|recovery_codes|api_key/i', (string) $k)) {
                if ((string) $o !== (string) $v) {
                    $d[$k] = ['from' => '[redacted]', 'to' => '[redacted]'];
                }
                continue;
            }
            if ((string) json_encode($o) !== (string) json_encode($v) && !(is_numeric($o) && is_numeric($v) && (float) $o === (float) $v)) {
                $d[$k] = ['from' => $o, 'to' => $v];
            }
        }
        return $d;
    }
}

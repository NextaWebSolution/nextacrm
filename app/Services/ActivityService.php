<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;

final class ActivityService
{
    public const TYPES = ['note' => 'Note', 'call' => 'Call', 'meeting' => 'Meeting', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'system' => 'Update'];

    public static function log(string $entity, int $recordId, string $type, string $body, ?string $subject = null, array $meta = [], ?string $occurredAt = null): int
    {
        return App::db()->insert('activities', [
            'company_id' => Auth::companyId(),
            'entity' => $entity,
            'record_id' => $recordId,
            'type' => array_key_exists($type, self::TYPES) ? $type : 'note',
            'subject' => $subject !== null ? mb_substr($subject, 0, 190) : null,
            'body' => mb_substr($body, 0, 20000),
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => Auth::id() ?: null,
            'occurred_at' => $occurredAt ?? DB::now(),
            'created_at' => DB::now(),
        ]);
    }

    public static function forRecord(string $entity, int $id, int $limit = 100): array
    {
        $rows = App::db()->all('SELECT * FROM activities WHERE company_id = ? AND entity = ? AND record_id = ? AND deleted_at IS NULL ORDER BY occurred_at DESC, id DESC LIMIT ' . (int) $limit, [Auth::companyId(), $entity, $id]);
        $users = LookupService::users(array_column($rows, 'user_id'));
        foreach ($rows as &$r) {
            $r['user'] = $users[(int) $r['user_id']] ?? null;
            $r['meta'] = json_decode((string) $r['meta'], true) ?: [];
        }
        return $rows;
    }

    public static function recent(int $limit = 15): array
    {
        $rows = App::db()->all("SELECT * FROM activities WHERE company_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT " . (int) ($limit * 3), [Auth::companyId()]);
        $out = [];
        foreach ($rows as $r) {
            if (!EntityRegistry::exists($r['entity'])) {
                continue;
            }
            $def = EntityRegistry::all()[$r['entity']];
            $level = \App\Core\Permissions::level($def['module'], 'view');
            // Users with own/team scope only see activity they authored in the global feed.
            if ($level === 'none' || ($level !== 'all' && (int) $r['user_id'] !== Auth::id())) {
                continue;
            }
            $r['title'] = LookupService::titles($r['entity'], [(int) $r['record_id']])[(int) $r['record_id']] ?? null;
            if ($r['title'] === null) {
                continue;
            }
            $r['entity_label'] = $def['singular'];
            $r['user'] = LookupService::users([(int) $r['user_id']])[(int) $r['user_id']] ?? null;
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}

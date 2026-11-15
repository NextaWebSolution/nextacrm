<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Permissions;

/**
 * Permission-aware global search across all entities (and files).
 * Each entity is queried through RecordService so tenant and scope rules always apply.
 */
final class SearchService
{
    public const ORDER = ['leads', 'customers', 'contacts', 'deals', 'projects', 'tasks', 'tickets', 'invoices', 'quotes', 'employees', 'contracts', 'kb_articles', 'products', 'expenses', 'payments', 'events', 'milestones', 'leave_requests', 'departments', 'time_entries'];

    public static function search(string $q, ?string $only = null, int $perEntity = 5): array
    {
        $q = trim(mb_substr($q, 0, 100));
        if (mb_strlen($q) < 2 && !ctype_digit($q)) {
            return [];
        }
        $groups = [];
        $entities = $only && EntityRegistry::exists($only) ? [$only] : self::ORDER;
        foreach ($entities as $key) {
            if (!EntityRegistry::exists($key)) {
                continue;
            }
            $def = EntityRegistry::all()[$key];
            if (!module_enabled($def['module']) || !Permissions::can($def['module'], 'view')) {
                continue;
            }
            $svc = RecordService::for($key);
            $res = $svc->list(['q' => $q, 'per_page' => $perEntity, 'sort' => 'updated_at', 'dir' => 'desc']);
            if (!$res['rows']) {
                continue;
            }
            $sub = $def['subtitle'] ?? null;
            $groups[] = [
                'entity' => $key,
                'label' => $def['label'],
                'icon' => $def['icon'],
                'total' => $res['total'],
                'items' => array_map(fn ($r) => [
                    'id' => (int) $r['id'],
                    'title' => $r['_title'],
                    'subtitle' => $sub ? (string) ($r['_labels'][$sub] ?? $r[$sub] ?? '') : '',
                    'status' => (string) ($r['status'] ?? ($r['_labels']['stage_id'] ?? '')),
                    'url' => url('/r/' . $key . '/' . $r['id']),
                ], $res['rows']),
            ];
        }
        if (!$only || $only === 'files') {
            $files = self::files($q, $perEntity);
            if ($files) {
                $groups[] = ['entity' => 'files', 'label' => 'Documents', 'icon' => 'file', 'total' => count($files), 'items' => $files];
            }
        }
        return $groups;
    }

    private static function files(string $q, int $limit): array
    {
        $level = Permissions::level('files', 'view');
        if ($level === 'none') {
            return [];
        }
        $db = App::db();
        $params = [Auth::companyId(), DB::like($q)];
        $sql = 'SELECT id, name, entity, record_id, size FROM files WHERE company_id = ? AND deleted_at IS NULL AND entity IS NULL AND name LIKE ?' . $db->likeEscape();
        if ($level === 'own') {
            $sql .= ' AND uploaded_by = ?';
            $params[] = Auth::id();
        }
        $rows = $db->all($sql . ' ORDER BY id DESC LIMIT ' . (int) $limit, $params);
        return array_map(fn ($f) => ['id' => (int) $f['id'], 'title' => $f['name'], 'subtitle' => FileService::humanSize((int) $f['size']), 'status' => '', 'url' => url('/files/' . $f['id'] . '/download')], $rows);
    }
}

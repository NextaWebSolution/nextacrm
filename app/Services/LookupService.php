<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Permissions;

final class LookupService
{
    private static array $userCache = [];

    /** id => title for records of an entity in the active company. */
    public static function titles(string $entity, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !EntityRegistry::exists($entity)) {
            return [];
        }
        $def = EntityRegistry::all()[$entity];
        $db = App::db();
        $title = $def['title'];
        $in = implode(',', $ids);
        $out = [];
        $titleField = $def['fields'][$title] ?? ['type' => 'text'];
        foreach ($db->all('SELECT id, ' . $db->ident($title) . ' AS t FROM ' . $db->ident($def['table']) . " WHERE id IN ($in) AND company_id = ?", [Auth::companyId()]) as $r) {
            $t = $r['t'];
            if (($titleField['type'] ?? '') === 'relation' && $t) {
                $t = self::titles($titleField['relation'], [(int) $t])[(int) $t] ?? '';
            }
            $out[(int) $r['id']] = (string) ($t !== null && $t !== '' ? $t : $def['singular'] . ' #' . $r['id']);
        }
        return $out;
    }

    /** id => [id, name, email, initials] */
    public static function users(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $missing = array_diff($ids, array_keys(self::$userCache));
        if ($missing) {
            $in = implode(',', $missing);
            foreach (App::db()->all("SELECT id, name, email, avatar_file_id FROM users WHERE id IN ($in)") as $u) {
                self::$userCache[(int) $u['id']] = ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'initials' => initials($u['name']), 'hue' => hue_for($u['email'])];
            }
        }
        return array_intersect_key(self::$userCache, array_flip($ids));
    }

    /** All active users of the current company (for pickers). */
    public static function companyUsers(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $rows = App::db()->all("SELECT u.id, u.name, u.email, u.job_title FROM users u JOIN company_user cu ON cu.user_id = u.id WHERE cu.company_id = ? AND u.deleted_at IS NULL AND u.status = 'active' ORDER BY u.name", [Auth::companyId()]);
        return $cache = array_map(fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'job_title' => $u['job_title'], 'initials' => initials($u['name']), 'hue' => hue_for($u['email'])], $rows);
    }

    /** Search records for relation pickers (permission scoped). */
    public static function search(string $entity, string $q, int $limit = 15, array $where = []): array
    {
        $svc = RecordService::for($entity);
        if (!Permissions::can($svc->module, 'view')) {
            return [];
        }
        $filters = [];
        foreach ($where as $f => $v) {
            if (isset($svc->def['fields'][$f]) && $v !== '' && $v !== null) {
                $filters[] = ['field' => $f, 'op' => 'eq', 'value' => $v];
            }
        }
        $res = $svc->list(['q' => $q, 'per_page' => $limit, 'filters' => $filters, 'sort' => $svc->def['title'] === 'number' ? 'created_at' : $svc->def['title'], 'dir' => $svc->def['title'] === 'number' ? 'desc' : 'asc']);
        $sub = $svc->def['subtitle'] ?? null;
        return array_map(function ($r) use ($sub) {
            $subtitle = $sub ? ($r['_labels'][$sub] ?? ($r[$sub] ?? '')) : '';
            return ['id' => (int) $r['id'], 'title' => $r['_title'], 'subtitle' => (string) $subtitle];
        }, $res['rows']);
    }

    public static function currencies(): array
    {
        static $c = null;
        return $c ??= App::db()->all('SELECT code, name, rate FROM currencies WHERE is_active = 1 ORDER BY code');
    }

    public static function taxes(): array
    {
        static $t = null;
        return $t ??= App::db()->all('SELECT id, name, rate, is_default FROM taxes WHERE company_id = ? ORDER BY rate', [Auth::companyId()]);
    }

    public static function pipelines(): array
    {
        static $p = null;
        if ($p !== null) {
            return $p;
        }
        $p = App::db()->all('SELECT * FROM pipelines WHERE company_id = ? ORDER BY is_default DESC, sort, id', [Auth::companyId()]);
        foreach ($p as &$pl) {
            $pl['stages'] = App::db()->all('SELECT * FROM pipeline_stages WHERE pipeline_id = ? ORDER BY sort, id', [$pl['id']]);
        }
        return $p;
    }

    /** Options for a field in a form/filter context: [[value, label], ...]. */
    public static function fieldOptions(array $f): array
    {
        return match ($f['type']) {
            'select', 'multiselect' => array_map(fn ($k, $v) => ['value' => (string) $k, 'label' => $v], array_keys(EntityRegistry::options($f)), EntityRegistry::options($f)),
            'user' => array_map(fn ($u) => ['value' => (string) $u['id'], 'label' => $u['name']], self::companyUsers()),
            'currency' => array_map(fn ($c) => ['value' => $c['code'], 'label' => $c['code'] . ' — ' . $c['name']], self::currencies()),
            'tax' => array_map(fn ($t) => ['value' => (string) $t['id'], 'label' => $t['name'] . ' (' . (float) $t['rate'] . '%)'], self::taxes()),
            'pipeline' => array_map(fn ($p) => ['value' => (string) $p['id'], 'label' => $p['name']], self::pipelines()),
            'stage' => array_merge(...array_map(fn ($p) => array_map(fn ($s) => ['value' => (string) $s['id'], 'label' => $s['name'], 'group' => $p['name'], 'pipeline' => (int) $p['id']], $p['stages']), self::pipelines() ?: [['stages' => [], 'name' => '', 'id' => 0]])),
            'bool' => [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']],
            default => [],
        };
    }
}

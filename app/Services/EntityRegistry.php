<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * Loads entity metadata from app/Entities and merges per-company custom fields.
 */
final class EntityRegistry
{
    private static ?array $defs = null;
    private static array $withCustom = [];

    public const FIELD_TYPES = ['text', 'textarea', 'email', 'phone', 'url', 'number', 'decimal', 'money', 'percent', 'date', 'datetime', 'select', 'multiselect', 'tags', 'bool', 'relation', 'user', 'currency', 'tax', 'pipeline', 'stage', 'duration', 'hidden'];
    public const CUSTOM_TYPES = ['text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number', 'decimal' => 'Decimal', 'money' => 'Money', 'date' => 'Date', 'datetime' => 'Date & time', 'select' => 'Dropdown', 'multiselect' => 'Multi-select', 'bool' => 'Checkbox', 'url' => 'URL', 'email' => 'Email', 'phone' => 'Phone'];

    public static function all(): array
    {
        if (self::$defs !== null) {
            return self::$defs;
        }
        $defs = [];
        $files = glob(NX_ROOT . '/app/Entities/*.php') ?: [];
        foreach (\App\Core\Extensions::entityDirs() as $dir) {
            $files = array_merge($files, glob(rtrim($dir, '/') . '/*.php') ?: []);
        }
        foreach ($files as $file) {
            $key = basename($file, '.php');
            $defs[$key] = self::normalize($key, require $file);
        }
        return self::$defs = $defs;
    }

    private static function normalize(string $key, array $d): array
    {
        $d['key'] = $key;
        $d['assignable'] ??= true;
        $d['company_scoped'] ??= true;
        $d['activity'] ??= true;
        $d['files'] ??= true;
        $d['nav'] ??= true;
        $d['search'] ??= [$d['title']];
        $d['related'] ??= [];
        $d['computed'] ??= [];
        if ($d['assignable']) {
            $d['fields']['assigned_to'] = ['type' => 'user', 'label' => $d['assigned_label'] ?? 'Owner', 'list' => true, 'filter' => true, 'group' => array_values($d['fields'])[0]['group'] ?? null];
        }
        $d['fields']['created_by'] = ['type' => 'user', 'label' => 'Created by', 'readonly' => true, 'filter' => true, 'system' => true];
        $d['fields']['created_at'] = ['type' => 'datetime', 'label' => 'Created', 'readonly' => true, 'filter' => true, 'system' => true];
        $d['fields']['updated_at'] = ['type' => 'datetime', 'label' => 'Updated', 'readonly' => true, 'system' => true];
        foreach ($d['fields'] as $name => &$f) {
            $f['name'] = $name;
            $f['label'] ??= ucwords(str_replace('_', ' ', $name));
            $f['type'] ??= 'text';
        }
        return $d;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** Definition including custom fields for the active company. */
    public static function get(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new HttpException(404);
        }
        $cid = Auth::check() ? Auth::companyId() : 0;
        $cacheKey = $key . ':' . $cid;
        if (isset(self::$withCustom[$cacheKey])) {
            return self::$withCustom[$cacheKey];
        }
        $def = $all[$key];
        if ($cid) {
            $rows = App::db()->all('SELECT * FROM custom_fields WHERE company_id = ? AND entity = ? ORDER BY sort, id', [$cid, $key]);
            foreach ($rows as $r) {
                $opts = json_decode((string) $r['options'], true) ?: [];
                $def['fields'][$r['name']] = [
                    'name' => $r['name'], 'label' => $r['label'], 'type' => $r['type'], 'custom' => true,
                    'required' => (bool) $r['required'], 'list' => (bool) $r['show_in_list'], 'filter' => in_array($r['type'], ['select', 'multiselect', 'bool', 'date'], true),
                    'options' => $opts, 'group' => 'Custom fields',
                ];
            }
        }
        return self::$withCustom[$cacheKey] = $def;
    }

    public static function flush(): void
    {
        self::$withCustom = [];
    }

    /** Resolve select options as value => label. */
    public static function options(array $field): array
    {
        $o = $field['options'] ?? [];
        if (is_string($o) && str_starts_with($o, 'picklist:')) {
            $o = (array) Settings::get('picklist.' . substr($o, 9), []);
        }
        if (!is_array($o)) {
            return [];
        }
        $out = [];
        $isList = array_is_list($o);
        foreach ($o as $k => $v) {
            // A plain list uses each value as its own key; a map (even with numeric keys) keeps its keys.
            $out[$isList ? (string) $v : (string) $k] = (string) $v;
        }
        return $out;
    }

    /** Navigation tree filtered by permission and enabled modules. */
    public static function nav(): array
    {
        $groups = [];
        foreach (self::all() as $key => $d) {
            if (!$d['nav'] || !module_enabled($d['module']) || !can($d['module'], 'view')) {
                continue;
            }
            $groups[$d['group'] ?? 'Other'][] = ['key' => $key, 'label' => $d['label'], 'icon' => $d['icon'], 'url' => url('/r/' . $key)];
        }
        foreach (\App\Core\Extensions::navItems() as $g => $items) {
            foreach ($items as $it) {
                $groups[$g][] = $it;
            }
        }
        $order = ['Sales' => 1, 'Marketing' => 2, 'Delivery' => 3, 'Support' => 4, 'Finance' => 5, 'Team' => 6];
        uksort($groups, fn ($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
        $sortOrder = ['leads', 'deals', 'customers', 'contacts', 'quotes', 'approvals', 'sequences', 'projects', 'tasks', 'time_entries', 'tickets', 'kb_articles', 'invoices', 'payments', 'expenses', 'products', 'contracts', 'employees', 'departments', 'leave_requests', 'attendance', 'payroll'];
        foreach ($groups as &$items) {
            usort($items, fn ($a, $b) => (array_search($a['key'], $sortOrder, true) ?: 99) <=> (array_search($b['key'], $sortOrder, true) ?: 99));
        }
        return $groups;
    }

    /** Fields visible to the current user (sensitive fields require the given permission). */
    public static function visibleFields(array $def): array
    {
        $out = [];
        foreach ($def['fields'] as $name => $f) {
            if (!empty($f['sensitive']) && !can($def['module'], $f['sensitive'])) {
                continue;
            }
            $out[$name] = $f;
        }
        return $out;
    }
}

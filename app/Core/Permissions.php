<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Role-based access control.
 * Scoped actions (view/edit/delete) take a level: none | own | team | all.
 * Other actions (create/export/import/approve/assign/manage) are booleans.
 */
final class Permissions
{
    public const ACTIONS = ['view', 'create', 'edit', 'delete', 'export', 'import', 'approve', 'assign', 'manage'];
    public const SCOPED = ['view', 'edit', 'delete'];
    public const LEVELS = ['none', 'own', 'team', 'all'];

    private static ?array $teamUserIds = null;

    /** Non-entity modules that still need permissions. */
    public static function extraModules(): array
    {
        return [
            'dashboard' => 'Dashboard',
            'reports' => 'Reports',
            'calendar' => 'Calendar',
            'files' => 'Files & documents',
            'workflows' => 'Automation',
            'settings' => 'Settings',
        ];
    }

    public static function modules(): array
    {
        $mods = [];
        foreach (\App\Services\EntityRegistry::all() as $key => $def) {
            if (!empty($def['module']) && empty($def['internal'])) {
                $mods[$def['module']] = $def['module_label'] ?? $def['label'];
            }
        }
        return $mods + self::extraModules();
    }

    public static function level(string $module, string $action, ?array $user = null): string
    {
        $user ??= Auth::user();
        if (!$user) {
            return 'none';
        }
        // API token scope is checked first: a read-only token stays read-only even when its owner is an administrator.
        $token = Auth::apiToken();
        if ($token && ($token['scope'] ?? 'read') === 'read' && $action !== 'view' && $action !== 'export') {
            return 'none';
        }
        if (!empty($user['is_admin'])) {
            return 'all';
        }
        $v = $user['permissions'][$module][$action] ?? null;
        if (in_array($action, self::SCOPED, true)) {
            return in_array($v, self::LEVELS, true) ? $v : 'none';
        }
        return $v ? 'all' : 'none';
    }

    public static function can(string $module, string $action, ?array $record = null): bool
    {
        $level = self::level($module, $action);
        if ($level === 'none') {
            return false;
        }
        if ($record === null || $level === 'all') {
            return true;
        }
        $uid = Auth::id();
        $owner = (int) ($record['assigned_to'] ?? 0);
        $creator = (int) ($record['created_by'] ?? 0);
        if ($owner === $uid || $creator === $uid) {
            return true;
        }
        if ($level === 'team') {
            $team = self::teamUserIds();
            return in_array($owner, $team, true) || in_array($creator, $team, true);
        }
        return false;
    }

    public static function authorize(string $module, string $action, ?array $record = null): void
    {
        if (!self::can($module, $action, $record)) {
            throw new HttpException(403);
        }
    }

    /**
     * SQL fragment restricting rows to what the user may see for the given scoped action.
     * @return array{0:string,1:array}
     */
    public static function scopeSql(string $module, string $action, string $alias, bool $hasAssigned): array
    {
        $level = self::level($module, $action);
        $uid = Auth::id();
        $a = preg_replace('/[^a-z_]/', '', $alias);
        return match ($level) {
            'all' => ['1=1', []],
            'none' => ['1=0', []],
            'own' => $hasAssigned
                ? ["($a.assigned_to = :_scope_uid OR $a.created_by = :_scope_uid2)", ['_scope_uid' => $uid, '_scope_uid2' => $uid]]
                : ["$a.created_by = :_scope_uid", ['_scope_uid' => $uid]],
            'team' => self::teamSql($a, $hasAssigned),
        };
    }

    private static function teamSql(string $a, bool $hasAssigned): array
    {
        $ids = self::teamUserIds();
        $ids[] = Auth::id();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $list = implode(',', $ids); // integers only — safe to inline
        return $hasAssigned
            ? ["($a.assigned_to IN ($list) OR $a.created_by IN ($list))", []]
            : ["$a.created_by IN ($list)", []];
    }

    /** IDs of users who share at least one team with the current user. */
    public static function teamUserIds(): array
    {
        if (self::$teamUserIds !== null) {
            return self::$teamUserIds;
        }
        $teams = Auth::user()['team_ids'] ?? [];
        if (!$teams) {
            return self::$teamUserIds = [Auth::id()];
        }
        $in = implode(',', array_map('intval', $teams));
        return self::$teamUserIds = array_map('intval', App::db()->column("SELECT DISTINCT user_id FROM team_user WHERE team_id IN ($in)"));
    }

    public static function reset(): void
    {
        self::$teamUserIds = null;
    }

    /** Default role matrices shipped with the installer. */
    public static function defaultRoles(): array
    {
        $all = fn (array $mods, array $p) => array_fill_keys($mods, $p);
        $full = ['view' => 'all', 'create' => true, 'edit' => 'all', 'delete' => 'all', 'export' => true, 'import' => true, 'approve' => true, 'assign' => true, 'manage' => true];
        $teamRW = ['view' => 'team', 'create' => true, 'edit' => 'team', 'delete' => 'own', 'export' => true, 'import' => false, 'approve' => false, 'assign' => true, 'manage' => false];
        $ownRW = ['view' => 'own', 'create' => true, 'edit' => 'own', 'delete' => 'none', 'export' => false, 'import' => false, 'approve' => false, 'assign' => false, 'manage' => false];
        $read = ['view' => 'all', 'create' => false, 'edit' => 'none', 'delete' => 'none', 'export' => false, 'import' => false, 'approve' => false, 'assign' => false, 'manage' => false];
        $base = ['dashboard' => $read, 'calendar' => $ownRW, 'files' => ['view' => 'all', 'create' => true, 'edit' => 'own', 'delete' => 'own']];

        $sales = ['leads', 'deals', 'customers', 'contacts', 'quotes'];
        $delivery = ['projects', 'tasks', 'time_entries', 'milestones'];
        $support = ['tickets', 'kb_articles'];
        $finance = ['invoices', 'payments', 'expenses', 'products', 'contracts'];
        $hr = ['employees', 'departments', 'leave_requests'];

        $mods = array_merge($sales, $delivery, $support, $finance, $hr, ['events', 'reports', 'workflows']);
        return [
            'Manager' => ['description' => 'Full access to business records of their teams, approvals and reports.', 'permissions' => $base + $all($mods, $teamRW + ['approve' => true, 'import' => true]) + ['reports' => $read, 'workflows' => $full]],
            'Sales' => ['description' => 'Leads, deals, customers, contacts and quotations.', 'permissions' => $base + $all($sales, $teamRW) + $all(['products'], $read) + ['tasks' => $ownRW, 'events' => $ownRW, 'invoices' => ['view' => 'team'] + $read, 'reports' => $read]],
            'Project Member' => ['description' => 'Assigned projects, tasks and own time entries.', 'permissions' => $base + ['projects' => ['view' => 'team'] + $read, 'milestones' => ['view' => 'team'] + $read, 'tasks' => $teamRW, 'time_entries' => $ownRW, 'events' => $ownRW, 'kb_articles' => $read, 'leave_requests' => $ownRW]],
            'Support Agent' => ['description' => 'Tickets, knowledge base and customer look-up.', 'permissions' => $base + $all($support, $teamRW) + ['customers' => $read, 'contacts' => $read, 'tasks' => $ownRW, 'events' => $ownRW, 'leave_requests' => $ownRW]],
            'Accountant' => ['description' => 'Invoices, payments, expenses and financial reports.', 'permissions' => $base + $all($finance, $full) + ['customers' => $read, 'projects' => $read, 'time_entries' => $read, 'reports' => $read, 'quotes' => $read]],
            'HR' => ['description' => 'Employees, departments and leave approvals.', 'permissions' => $base + $all($hr, $full) + ['events' => $ownRW]],
        ];
    }
}

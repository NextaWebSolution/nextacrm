<?php
/**
 * 2.0.0: client portal, payments, e-sign, inbox, WhatsApp, sequences, report builder, approvals,
 * attendance & payroll, AI usage, modules. Additive only: creates missing tables, columns and indexes.
 *
 * Note: migrations run inside the PHP process of the *previous* version during an update, so classes that
 * were already loaded (e.g. Permissions) are the old ones. Keep this file self-contained.
 */
return static function (App\Core\DB $db): void {
    App\Services\Migrator::syncSchema();

    // Give the default roles access to the new permission modules (only where not set yet).
    $full = ['view' => 'all', 'create' => true, 'edit' => 'all', 'delete' => 'all', 'export' => true, 'import' => true, 'approve' => true, 'assign' => true, 'manage' => true];
    $read = ['view' => 'all', 'create' => false, 'edit' => 'none', 'delete' => 'none', 'export' => false, 'import' => false, 'approve' => false, 'assign' => false, 'manage' => false];
    $own = ['view' => 'own'];
    $defaults = [
        'HR' => ['attendance' => $full, 'payroll' => $full],
        'Manager' => ['attendance' => $read],
        'Accountant' => ['attendance' => $own, 'payroll' => ['view' => 'all', 'approve' => true]],
        'Sales' => ['attendance' => $own],
        'Project Member' => ['attendance' => $own],
        'Support Agent' => ['attendance' => $own],
    ];
    foreach ($db->all('SELECT id, name, permissions FROM roles WHERE is_admin = 0') as $role) {
        $add = $defaults[$role['name']] ?? ['attendance' => $own];
        $perms = json_decode((string) $role['permissions'], true) ?: [];
        $changed = false;
        foreach ($add as $mod => $p) {
            if (!isset($perms[$mod])) {
                $perms[$mod] = $p;
                $changed = true;
            }
        }
        if ($changed) {
            $db->update('roles', ['permissions' => json_encode($perms)], ['id' => (int) $role['id']]);
        }
    }
};

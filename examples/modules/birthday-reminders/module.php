<?php
/**
 * Sample NextaCRM module. Loaded on every request while enabled.
 * Keep this file light: register hooks here and do the work inside the callbacks.
 */
defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Extensions as X;
use App\Core\View;

X::viewDir('birthday-reminders', __DIR__ . '/views');

// A card in the sidebar of every employee record.
X::panel('employees', 'side', fn (array $rec) => View::partial('birthday-reminders/panel', ['rec' => $rec]), 60);

// Once a day, for every company: create a reminder task 3 days before a birthday.
X::cron('birthday_reminders', function (): int {
    $db = App::db();
    $target = date('m-d', strtotime('+3 days'));
    $made = 0;
    foreach ($db->all("SELECT id, first_name, last_name, date_of_birth, assigned_to FROM employees WHERE company_id = ? AND deleted_at IS NULL AND status <> 'Exited' AND date_of_birth IS NOT NULL", [Auth::companyId()]) as $e) {
        if (substr((string) $e['date_of_birth'], 5, 5) !== $target) {
            continue;
        }
        $title = 'Birthday: ' . trim($e['first_name'] . ' ' . $e['last_name']) . ' on ' . date('j M', strtotime('+3 days'));
        if ($db->value('SELECT id FROM tasks WHERE company_id = ? AND title = ? AND deleted_at IS NULL', [Auth::companyId(), $title])) {
            continue; // already created
        }
        $db->insert('tasks', [
            'company_id' => Auth::companyId(), 'title' => $title, 'status' => 'To Do', 'priority' => 'Medium',
            'due_date' => date('Y-m-d', strtotime('+2 days')), 'assigned_to' => $e['assigned_to'] ?: null,
            'created_at' => DB::now(), 'updated_at' => DB::now(),
        ]);
        $made++;
    }
    return $made;
}, true, true);

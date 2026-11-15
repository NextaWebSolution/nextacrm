<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;

/** Daily contract maintenance: renewal reminders (once per contract term) and expiry. Runs per company. */
final class ContractService
{
    public static function runRenewals(): int
    {
        $db = App::db();
        $cid = Auth::companyId();
        $today = date('Y-m-d');
        $n = 0;
        $rows = $db->all("SELECT * FROM contracts WHERE company_id = ? AND deleted_at IS NULL AND status = 'Active' AND end_date IS NOT NULL AND end_date >= ? AND renewal_notified_at IS NULL", [$cid, $today]);
        foreach ($rows as $c) {
            $days = max(0, (int) ($c['renewal_reminder_days'] ?? 30));
            if (date('Y-m-d', strtotime($c['end_date'] . " -$days days")) > $today) {
                continue;
            }
            $owner = (int) ($c['assigned_to'] ?: $c['created_by']);
            $left = (int) round((strtotime($c['end_date']) - strtotime($today)) / 86400);
            $title = 'Contract renewal due: ' . $c['title'];
            if ($owner) {
                NotificationService::notify($owner, 'contract.renewal', $title, "Ends on " . fmt_date($c['end_date']) . " ($left days). Plan the renewal with the client.", '/r/contracts/' . $c['id']);
                $db->insert('tasks', [
                    'company_id' => $cid, 'title' => 'Renew contract: ' . mb_substr($c['title'], 0, 150), 'status' => 'To Do', 'priority' => 'High',
                    'due_date' => date('Y-m-d', strtotime($c['end_date'] . ' -3 days')) < $today ? $today : date('Y-m-d', strtotime($c['end_date'] . ' -3 days')),
                    'related_entity' => 'contracts', 'related_id' => (int) $c['id'], 'assigned_to' => $owner, 'created_by' => $owner, 'created_at' => DB::now(), 'updated_at' => DB::now(),
                ]);
            }
            ActivityService::log('contracts', (int) $c['id'], 'system', "Renewal reminder sent ($left days before the end date).");
            $db->update('contracts', ['renewal_notified_at' => DB::now()], ['id' => $c['id']]);
            $n++;
        }
        // Lapsed contracts.
        $db->query("UPDATE contracts SET status = 'Expired', updated_at = ? WHERE company_id = ? AND deleted_at IS NULL AND status = 'Active' AND end_date IS NOT NULL AND end_date < ?", [DB::now(), $cid, $today]);
        return $n;
    }
}

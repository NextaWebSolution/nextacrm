<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

final class ProjectService
{
    public static function addMember(int $projectId, int $userId, ?string $role = null): void
    {
        $db = App::db();
        if (!$db->value('SELECT id FROM project_members WHERE project_id = ? AND user_id = ?', [$projectId, $userId])) {
            $db->insert('project_members', ['project_id' => $projectId, 'user_id' => $userId, 'role' => $role]);
        }
    }

    public static function members(int $projectId): array
    {
        $rows = App::db()->all('SELECT pm.*, u.name, u.email, u.job_title FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = ? AND u.deleted_at IS NULL ORDER BY u.name', [$projectId]);
        foreach ($rows as &$r) {
            $r['hue'] = hue_for($r['email']);
        }
        return $rows;
    }

    /** Project IDs where the current user is a member (grants view access to project, tasks, milestones). */
    public static function memberProjectIds(): array
    {
        static $ids = null;
        return $ids ??= array_map('intval', App::db()->column('SELECT pm.project_id FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE pm.user_id = ? AND p.company_id = ?', [Auth::id(), Auth::companyId()]));
    }

    public static function stats(int $projectId): array
    {
        $db = App::db();
        $t = $db->one("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Done' THEN 1 ELSE 0 END) AS done, SUM(CASE WHEN status<>'Done' AND due_date < ? THEN 1 ELSE 0 END) AS overdue, COALESCE(SUM(estimate_hours),0) AS est FROM tasks WHERE project_id = ? AND deleted_at IS NULL", [date('Y-m-d'), $projectId]);
        $time = $db->one('SELECT COALESCE(SUM(minutes),0) AS mins, COALESCE(SUM(CASE WHEN is_billable=1 THEN minutes ELSE 0 END),0) AS billable, COALESCE(SUM(CASE WHEN is_billable=1 AND is_invoiced=0 THEN minutes ELSE 0 END),0) AS unbilled, COALESCE(SUM(CASE WHEN is_billable=1 THEN minutes*COALESCE(hourly_rate,0)/60 ELSE 0 END),0) AS value FROM time_entries WHERE project_id = ? AND deleted_at IS NULL', [$projectId]);
        $inv = $db->one("SELECT COALESCE(SUM(total),0) AS invoiced, COALESCE(SUM(amount_paid),0) AS paid FROM invoices WHERE project_id = ? AND deleted_at IS NULL AND status <> 'Cancelled'", [$projectId]);
        $exp = (float) $db->value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE project_id = ? AND deleted_at IS NULL AND status <> 'Rejected'", [$projectId]);
        return [
            'tasks_total' => (int) $t['total'], 'tasks_done' => (int) $t['done'], 'tasks_overdue' => (int) $t['overdue'], 'estimate_hours' => (float) $t['est'],
            'hours' => round((int) $time['mins'] / 60, 1), 'billable_hours' => round((int) $time['billable'] / 60, 1), 'unbilled_hours' => round((int) $time['unbilled'] / 60, 1),
            'time_value' => round((float) $time['value'], 2), 'invoiced' => (float) $inv['invoiced'], 'paid' => (float) $inv['paid'], 'expenses' => $exp,
        ];
    }

    // ───── Timer ─────

    public static function runningTimer(): ?array
    {
        $row = App::db()->one('SELECT te.*, t.title AS task_title, p.name AS project_name FROM time_entries te LEFT JOIN tasks t ON t.id = te.task_id LEFT JOIN projects p ON p.id = te.project_id WHERE te.assigned_to = ? AND te.company_id = ? AND te.is_running = 1 AND te.deleted_at IS NULL ORDER BY te.id DESC LIMIT 1', [Auth::id(), Auth::companyId()]);
        return $row ?: null;
    }

    public static function startTimer(?int $taskId, ?int $projectId, string $description = ''): array
    {
        self::stopTimer();
        $svc = RecordService::for('time_entries');
        if ($taskId) {
            $task = RecordService::for('tasks')->find($taskId);
            $projectId = $task['project_id'] ? (int) $task['project_id'] : $projectId;
            if ($description === '') {
                $description = $task['title'];
            }
        }
        $rec = $svc->create(['task_id' => $taskId, 'project_id' => $projectId, 'entry_date' => date('Y-m-d'), 'minutes' => 0, 'description' => $description, 'is_billable' => 1], ['silent' => true]);
        App::db()->update('time_entries', ['is_running' => 1, 'started_at' => DB::now()], ['id' => $rec['id']]);
        return self::runningTimer() ?? $rec;
    }

    public static function stopTimer(): ?array
    {
        $run = self::runningTimer();
        if (!$run) {
            return null;
        }
        $minutes = max(1, (int) round((time() - strtotime($run['started_at'] . ' UTC')) / 60));
        App::db()->update('time_entries', ['is_running' => 0, 'ended_at' => DB::now(), 'minutes' => (int) $run['minutes'] + $minutes], ['id' => $run['id']]);
        EntityHooks::recalcTask((int) $run['task_id']);
        return App::db()->find('time_entries', (int) $run['id']);
    }
}

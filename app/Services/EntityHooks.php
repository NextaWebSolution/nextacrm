<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * Entity-specific business rules that run inside the record transaction.
 */
final class EntityHooks
{
    /** Set while recording a payment a gateway has already collected (overpayment is then recorded, not refused). */
    public static bool $gatewayPayment = false;

    public static function before(RecordService $svc, array &$data, ?array $old): void
    {
        $m = 'before_' . $svc->entity;
        if (method_exists(self::class, $m)) {
            self::$m($svc, $data, $old);
        }
    }

    public static function after(RecordService $svc, array $record, ?array $old): void
    {
        $m = 'after_' . $svc->entity;
        if (method_exists(self::class, $m)) {
            self::$m($svc, $record, $old);
        }
    }

    public static function deleted(RecordService $svc, array $old): void
    {
        $db = App::db();
        match ($svc->entity) {
            'payments' => SalesService::recalcInvoice((int) $old['invoice_id']),
            'time_entries' => self::recalcTask((int) $old['task_id']),
            'tasks' => self::recalcProject((int) $old['project_id']),
            // Soft-deleting an invoice releases its billed time so it can be invoiced again.
            'invoices' => $db->query('UPDATE time_entries SET is_invoiced = 0, invoice_id = NULL WHERE invoice_id = ?', [(int) $old['id']]),
            default => null,
        };
    }

    // ── Leads ──
    private static function before_leads(RecordService $svc, array &$data, ?array $old): void
    {
        $merged = array_merge($old ?? [], $data);
        $data['score'] = self::leadScore($merged);
        // Duplicate detection (Odoo-style) — warn, never block.
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        if (($email && (!$old || $email !== $old['email'])) || ($phone && (!$old || $phone !== $old['phone']))) {
            $dup = App::db()->one('SELECT id, name FROM leads WHERE company_id = ? AND deleted_at IS NULL AND id <> ? AND ((email IS NOT NULL AND email = ?) OR (phone IS NOT NULL AND phone = ?)) LIMIT 1', [Auth::companyId(), (int) ($old['id'] ?? 0), (string) $email, (string) $phone]);
            if ($dup) {
                $svc->warnings[] = 'Possible duplicate of lead #' . $dup['id'] . ' (' . $dup['name'] . ').';
            }
        }
    }

    public static function leadScore(array $lead): int
    {
        $score = 0;
        foreach ((array) setting('lead_scoring.rules', []) as $r) {
            $v = $lead[$r['field'] ?? ''] ?? null;
            $match = match ($r['op'] ?? '') {
                'filled' => $v !== null && $v !== '',
                'eq' => (string) $v === (string) ($r['value'] ?? ''),
                'gt' => is_numeric($v) && (float) $v > (float) ($r['value'] ?? 0),
                'contains' => $v !== null && stripos((string) $v, (string) ($r['value'] ?? '')) !== false,
                default => false,
            };
            if ($match) {
                $score += (int) ($r['points'] ?? 0);
            }
        }
        return max(0, min(100, $score));
    }

    // ── Deals ──
    private static function before_deals(RecordService $svc, array &$data, ?array $old): void
    {
        $db = App::db();
        $cid = Auth::companyId();
        $pipelineId = (int) ($data['pipeline_id'] ?? $old['pipeline_id'] ?? 0);
        if (!$pipelineId) {
            $pipelineId = (int) $db->value('SELECT id FROM pipelines WHERE company_id = ? ORDER BY is_default DESC, sort, id LIMIT 1', [$cid]);
            $data['pipeline_id'] = $pipelineId ?: null;
        }
        $stageId = (int) ($data['stage_id'] ?? $old['stage_id'] ?? 0);
        $stage = $stageId ? $db->one('SELECT * FROM pipeline_stages WHERE id = ? AND pipeline_id = ?', [$stageId, $pipelineId]) : null;
        if (!$stage) {
            $stage = $db->one('SELECT * FROM pipeline_stages WHERE pipeline_id = ? ORDER BY sort, id LIMIT 1', [$pipelineId]);
            $data['stage_id'] = $stage['id'] ?? null;
        }
        if (!$old && empty($data['currency'])) {
            $data['currency'] = CompanyService::currentCurrency();
        }
        $stageChanged = !$old || (int) $old['stage_id'] !== (int) ($data['stage_id'] ?? $old['stage_id']);
        if ($stage && $stageChanged) {
            $data['stage_changed_at'] = DB::now();
            $explicit = array_key_exists('probability', $data) && $data['probability'] !== null
                && (!$old || (int) $data['probability'] !== (int) $old['probability']);
            if (!$explicit) {
                $data['probability'] = (int) $stage['probability'];
            }
            $status = $stage['is_won'] ? 'won' : ($stage['is_lost'] ? 'lost' : 'open');
            $data['status'] = $status;
            $data['closed_at'] = $status === 'open' ? null : DB::now();
        }
    }

    // ── Quotes & invoices (generic edits only; line items handled by SalesService) ──
    private static function before_quotes(RecordService $svc, array &$data, ?array $old): void
    {
        ApprovalService::guard('quotes', $data, $old);
        if (!$old) {
            $data['number'] ??= CompanyService::nextNumber('quote');
            $data['issue_date'] ??= date('Y-m-d');
            $data['currency'] ??= CompanyService::currentCurrency();
            $data['public_token'] = \App\Core\Crypto::token(24);
        }
    }

    private static function before_invoices(RecordService $svc, array &$data, ?array $old): void
    {
        if (!$old) {
            $data['number'] ??= CompanyService::nextNumber('invoice');
            $data['issue_date'] ??= date('Y-m-d');
            $data['due_date'] ??= date('Y-m-d', strtotime('+' . (int) (CompanyService::current()['payment_terms_days'] ?? 15) . ' days'));
            $data['currency'] ??= CompanyService::currentCurrency();
            $data['public_token'] = \App\Core\Crypto::token(24);
        }
        if (!empty($data['is_recurring']) && empty($data['recurring_next_date']) && empty($old['recurring_next_date'])) {
            $data['recurring_next_date'] = SalesService::nextRecurringDate($data['issue_date'] ?? $old['issue_date'] ?? date('Y-m-d'), $data['recurring_interval'] ?? $old['recurring_interval'] ?? 'Monthly');
        }
    }

    // ── Payments ──
    private static function before_payments(RecordService $svc, array &$data, ?array $old): void
    {
        $invoiceId = (int) ($data['invoice_id'] ?? $old['invoice_id'] ?? 0);
        $db = App::db();
        // Lock the invoice row so two simultaneous payments cannot both pass the balance check.
        $inv = $db->one('SELECT * FROM invoices WHERE id = ? AND company_id = ? AND deleted_at IS NULL' . $db->forUpdate(), [$invoiceId, Auth::companyId()]);
        if (!$inv) {
            throw new HttpException(422, '', ['invoice_id' => 'Invoice not found.']);
        }
        if ($inv['status'] === 'Cancelled') {
            throw new HttpException(422, '', ['invoice_id' => 'Payments cannot be recorded on a cancelled invoice.']);
        }
        if (isset($data['amount']) && (float) $data['amount'] <= 0) {
            throw new HttpException(422, '', ['amount' => 'Amount must be greater than zero.']);
        }
        $amount = (float) ($data['amount'] ?? $old['amount'] ?? 0);
        $paidOthers = (float) $db->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ? AND deleted_at IS NULL AND id <> ?', [$invoiceId, (int) ($old['id'] ?? 0)]);
        $remaining = round((float) $inv['total'] - $paidOthers, 2);
        if ($amount - $remaining > 0.004 && !self::$gatewayPayment) {
            throw new HttpException(422, '', ['amount' => 'Amount exceeds the outstanding balance (' . number_format(max(0, $remaining), 2) . ').']);
        }
        $data['customer_id'] = $inv['customer_id'];
        $data['currency'] = $inv['currency'];
    }

    private static function after_payments(RecordService $svc, array $record, ?array $old): void
    {
        SalesService::recalcInvoice((int) $record['invoice_id']);
        if ($old && (int) $old['invoice_id'] !== (int) $record['invoice_id']) {
            SalesService::recalcInvoice((int) $old['invoice_id']);
        }
    }

    // ── Projects ──
    private static function before_projects(RecordService $svc, array &$data, ?array $old): void
    {
        if (!$old && empty($data['currency'])) {
            $data['currency'] = CompanyService::currentCurrency();
        }
        $status = $data['status'] ?? null;
        if ($status !== null && (!$old || $old['status'] !== $status)) {
            $data['completed_at'] = $status === 'Completed' ? DB::now() : null;
        }
    }

    private static function after_projects(RecordService $svc, array $record, ?array $old): void
    {
        // Project manager is always a member.
        if (!empty($record['assigned_to'])) {
            ProjectService::addMember((int) $record['id'], (int) $record['assigned_to'], 'Manager');
        }
    }

    // ── Tasks ──
    private static function before_tasks(RecordService $svc, array &$data, ?array $old): void
    {
        $status = $data['status'] ?? null;
        if ($status !== null && (!$old || $old['status'] !== $status)) {
            if ($status === 'Done') {
                $data['completed_at'] = DB::now();
                $data['progress'] = 100;
                self::assertDependenciesDone($data['depends_on'] ?? $old['depends_on'] ?? null, (int) ($old['id'] ?? 0));
            } else {
                $data['completed_at'] = null;
            }
        }
        if (isset($data['depends_on'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $data['depends_on']) ?: []))));
            $ids = array_values(array_filter($ids, fn ($i) => $i !== (int) ($old['id'] ?? 0)));
            $data['depends_on'] = $ids ? implode(',', $ids) : null;
        }
        if (!empty($data['parent_id']) && $old && (int) $data['parent_id'] === (int) $old['id']) {
            throw new HttpException(422, '', ['parent_id' => 'A task cannot be its own parent.']);
        }
    }

    private static function assertDependenciesDone(?string $dependsOn, int $selfId): void
    {
        if (!$dependsOn) {
            return;
        }
        $ids = array_filter(array_map('intval', explode(',', $dependsOn)), fn ($i) => $i !== $selfId);
        if (!$ids) {
            return;
        }
        $in = implode(',', $ids);
        $open = App::db()->column("SELECT id FROM tasks WHERE id IN ($in) AND company_id = ? AND deleted_at IS NULL AND status <> 'Done'", [Auth::companyId()]);
        if ($open) {
            throw new HttpException(422, 'This task is blocked by unfinished task(s): #' . implode(', #', $open), ['status' => 'Blocked by unfinished tasks.']);
        }
    }

    private static function after_tasks(RecordService $svc, array $record, ?array $old): void
    {
        if ($record['status'] === 'Done' && (!$old || $old['status'] !== 'Done') && !empty($record['recurrence'])) {
            self::spawnRecurringTask($record);
        }
        self::recalcProject((int) $record['project_id']);
        if ($old && (int) $old['project_id'] !== (int) $record['project_id']) {
            self::recalcProject((int) $old['project_id']);
        }
    }

    /** Creates the next occurrence of a repeating task (once per completed task). */
    private static function spawnRecurringTask(array $t): void
    {
        $db = App::db();
        if ($db->value('SELECT id FROM tasks WHERE recurred_from = ? AND deleted_at IS NULL', [(int) $t['id']])) {
            return;
        }
        $step = ['Daily' => '+1 day', 'Weekly' => '+1 week', 'Monthly' => '+1 month', 'Quarterly' => '+3 months', 'Yearly' => '+1 year'][$t['recurrence']] ?? null;
        if (!$step) {
            return;
        }
        $shift = fn (?string $d) => $d ? date('Y-m-d', (int) strtotime($d . ' ' . $step)) : null;
        $due = $shift($t['due_date']) ?? date('Y-m-d', (int) strtotime('today ' . $step));
        if (!empty($t['recur_until']) && $due > $t['recur_until']) {
            return;
        }
        $checklist = json_decode((string) ($t['checklist'] ?? ''), true);
        $checklist = is_array($checklist) ? array_map(fn ($i) => ['t' => (string) ($i['t'] ?? ''), 'd' => false], $checklist) : null;
        $copy = array_intersect_key($t, array_flip(['company_id', 'title', 'project_id', 'milestone_id', 'parent_id', 'related_entity', 'related_id', 'priority', 'estimate_hours', 'is_billable', 'tags', 'description', 'recurrence', 'recur_until', 'assigned_to', 'custom']));
        $now = DB::now();
        $id = $db->insert('tasks', $copy + [
            'status' => 'To Do', 'start_date' => $shift($t['start_date']), 'due_date' => $due, 'progress' => 0, 'spent_hours' => 0,
            'checklist' => $checklist ? json_encode($checklist) : null, 'recurred_from' => (int) $t['id'],
            'created_by' => Auth::id() ?: $t['created_by'], 'updated_by' => Auth::id() ?: null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        ActivityService::log('tasks', (int) $t['id'], 'system', 'Next occurrence created: task #' . $id . ' due ' . $due);
    }

    public static function recalcProject(int $projectId): void
    {
        if (!$projectId) {
            return;
        }
        $db = App::db();
        $row = $db->one("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) AS done FROM tasks WHERE project_id = ? AND deleted_at IS NULL", [$projectId]);
        $progress = (int) $row['total'] > 0 ? (int) round(((int) $row['done'] / (int) $row['total']) * 100) : 0;
        $db->update('projects', ['progress' => $progress], ['id' => $projectId]);
    }

    // ── Time entries ──
    private static function before_time_entries(RecordService $svc, array &$data, ?array $old): void
    {
        if (!$old) {
            $data['entry_date'] ??= date('Y-m-d');
        }
        $taskId = (int) ($data['task_id'] ?? $old['task_id'] ?? 0);
        if ($taskId && empty($data['project_id']) && empty($old['project_id'])) {
            $data['project_id'] = App::db()->value('SELECT project_id FROM tasks WHERE id = ?', [$taskId]) ?: null;
        }
        $projectId = (int) ($data['project_id'] ?? $old['project_id'] ?? 0);
        if ($projectId && !array_key_exists('hourly_rate', $data) && empty($old['hourly_rate'])) {
            $rate = App::db()->value('SELECT hourly_rate FROM projects WHERE id = ?', [$projectId]);
            if ($rate) {
                $data['hourly_rate'] = (float) $rate;
            }
        }
        if ($old && !empty($old['is_invoiced']) && (isset($data['minutes']) || isset($data['hourly_rate']))) {
            throw new HttpException(422, 'This time entry has already been invoiced and cannot be changed.');
        }
    }

    private static function after_time_entries(RecordService $svc, array $record, ?array $old): void
    {
        self::recalcTask((int) $record['task_id']);
        if ($old && (int) $old['task_id'] !== (int) $record['task_id']) {
            self::recalcTask((int) $old['task_id']);
        }
    }

    public static function recalcTask(int $taskId): void
    {
        if (!$taskId) {
            return;
        }
        $min = (int) App::db()->value('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE task_id = ? AND deleted_at IS NULL', [$taskId]);
        App::db()->update('tasks', ['spent_hours' => round($min / 60, 2)], ['id' => $taskId]);
    }

    // ── Tickets ──
    private static function before_tickets(RecordService $svc, array &$data, ?array $old): void
    {
        if (!$old) {
            $data['number'] = CompanyService::nextNumber('ticket');
        }
        $priority = $data['priority'] ?? $old['priority'] ?? 'Medium';
        if (!$old || (isset($data['priority']) && $data['priority'] !== $old['priority'])) {
            $base = $old ? strtotime($old['created_at'] . ' UTC') : time();
            $policy = ((array) setting('sla.policies', []))[$priority] ?? null;
            if ($policy) {
                $data['first_response_due'] = gmdate('Y-m-d H:i:s', $base + (int) $policy['response'] * 3600);
                $data['resolution_due'] = gmdate('Y-m-d H:i:s', $base + (int) $policy['resolution'] * 3600);
                $data['sla_status'] = 'On Track';
            }
        }
        $status = $data['status'] ?? null;
        if ($status !== null && (!$old || $old['status'] !== $status)) {
            if (in_array($status, ['Resolved', 'Closed'], true)) {
                $data['resolved_at'] = $old['resolved_at'] ?? DB::now();
                $due = $data['resolution_due'] ?? $old['resolution_due'] ?? null;
                $data['sla_status'] = $due && strtotime($due . ' UTC') < time() ? 'Breached' : 'Met';
            } else {
                $data['resolved_at'] = null;
            }
        }
        if (!$old && empty($data['customer_id']) && !empty($data['contact_id'])) {
            $data['customer_id'] = App::db()->value('SELECT customer_id FROM contacts WHERE id = ?', [(int) $data['contact_id']]) ?: null;
        }
    }

    // ── Knowledge base ──
    private static function before_kb_articles(RecordService $svc, array &$data, ?array $old): void
    {
        if (isset($data['title'])) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $data['title']) ?? '', '-'));
            $data['slug'] = substr($slug ?: 'article', 0, 180);
        }
    }

    // ── HR ──
    private static function before_leave_requests(RecordService $svc, array &$data, ?array $old): void
    {
        $start = $data['start_date'] ?? $old['start_date'] ?? null;
        $end = $data['end_date'] ?? $old['end_date'] ?? null;
        if ($start && $end && $end < $start) {
            throw new HttpException(422, '', ['end_date' => 'End date must be on or after the start date.']);
        }
        if ($start && $end && (empty($data['days']) && (isset($data['start_date']) || isset($data['end_date'])))) {
            $days = 0;
            for ($d = strtotime($start); $d <= strtotime($end); $d += 86400) {
                if ((int) date('N', $d) < 6) {
                    $days++;
                }
            }
            $data['days'] = max(0.5, $days);
        }
        if (!$old) {
            // Employees requesting leave for themselves: approver defaults to their manager's user.
            $emp = App::db()->one('SELECT e.*, m.user_id AS manager_user_id FROM employees e LEFT JOIN employees m ON m.id = e.manager_id WHERE e.id = ?', [(int) ($data['employee_id'] ?? 0)]);
            if ($emp && !empty($emp['manager_user_id']) && empty($data['assigned_to'])) {
                $data['assigned_to'] = (int) $emp['manager_user_id'];
            }
        }
    }

    private static function after_leave_requests(RecordService $svc, array $record, ?array $old): void
    {
        $wasApproved = $old && $old['status'] === 'Approved';
        $isApproved = $record['status'] === 'Approved';
        if ($wasApproved !== $isApproved) {
            $delta = (float) $record['days'] * ($isApproved ? -1 : 1);
            App::db()->query('UPDATE employees SET leave_balance = leave_balance + ? WHERE id = ?', [$delta, (int) $record['employee_id']]);
        }
    }

    private static function before_expenses(RecordService $svc, array &$data, ?array $old): void
    {
        ApprovalService::guard('expenses', $data, $old);
        if (!$old && empty($data['currency'])) {
            $data['currency'] = CompanyService::currentCurrency();
        }
    }

    private static function before_contracts(RecordService $svc, array &$data, ?array $old): void
    {
        if ($old && array_key_exists('end_date', $data) && $data['end_date'] !== $old['end_date']) {
            $data['renewal_notified_at'] = null; // new term → remind again
        }
        $start = $data['start_date'] ?? $old['start_date'] ?? null;
        $end = $data['end_date'] ?? $old['end_date'] ?? null;
        if ($start && $end && $end < $start) {
            throw new HttpException(422, '', ['end_date' => 'End date must be after the start date.']);
        }
    }

    // ── Calendar ──
    private static function before_events(RecordService $svc, array &$data, ?array $old): void
    {
        $start = $data['starts_at'] ?? $old['starts_at'] ?? null;
        if ($start && empty($data['ends_at']) && empty($old['ends_at'])) {
            $data['ends_at'] = gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + 3600);
        }
        $end = $data['ends_at'] ?? $old['ends_at'] ?? null;
        if ($start && $end && $end < $start) {
            throw new HttpException(422, '', ['ends_at' => 'End must be after the start.']);
        }
        if (isset($data['starts_at']) || isset($data['reminder_minutes'])) {
            $data['reminder_sent'] = 0;
        }
    }

    private static function before_contacts(RecordService $svc, array &$data, ?array $old): void
    {
        if (!empty($data['is_primary']) && !empty($data['customer_id'] ?? $old['customer_id'] ?? null)) {
            App::db()->query('UPDATE contacts SET is_primary = 0 WHERE customer_id = ? AND id <> ?', [(int) ($data['customer_id'] ?? $old['customer_id']), (int) ($old['id'] ?? 0)]);
        }
    }
}

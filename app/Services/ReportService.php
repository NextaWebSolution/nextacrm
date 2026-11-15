<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\Permissions;

/**
 * Dashboard KPIs and reports. Every query applies company + permission scope via scopeFor().
 */
final class ReportService
{
    /** WHERE fragment for an entity table aliased as t, respecting view scope. */
    private static function scope(string $entity): array
    {
        $svc = RecordService::for($entity);
        [$where, $params] = $svc->baseWhere('view');
        return [implode(' AND ', $where), $params, $svc->table];
    }

    private static function canView(string $module): bool
    {
        return Permissions::can($module, 'view') && module_enabled($module);
    }

    public static function kpis(): array
    {
        $db = App::db();
        $out = [];
        $monthStart = date('Y-m-01');
        $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
        $lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));

        if (self::canView('leads')) {
            [$w, $p, $t] = self::scope('leads');
            $cur = (int) $db->value("SELECT COUNT(*) FROM $t t WHERE $w AND t.created_at >= :ms", $p + ['ms' => $monthStart . ' 00:00:00']);
            $prev = (int) $db->value("SELECT COUNT(*) FROM $t t WHERE $w AND t.created_at BETWEEN :a AND :b", $p + ['a' => $lastMonthStart . ' 00:00:00', 'b' => $lastMonthEnd . ' 23:59:59']);
            $out[] = ['label' => 'New leads this month', 'value' => number_format($cur), 'delta' => self::delta($cur, $prev), 'icon' => 'target', 'url' => url('/r/leads')];
        }
        if (self::canView('deals')) {
            [$w, $p, $t] = self::scope('deals');
            $open = $db->one("SELECT COUNT(*) AS c, COALESCE(SUM(t.amount),0) AS amt, COALESCE(SUM(t.amount * t.probability / 100),0) AS weighted FROM $t t WHERE $w AND t.status = 'open'", $p);
            $out[] = ['label' => 'Open pipeline', 'value' => money($open['amt']), 'sub' => 'Weighted ' . money($open['weighted']) . ' · ' . (int) $open['c'] . ' deals', 'icon' => 'handshake', 'url' => url('/r/deals')];
            $won = (float) $db->value("SELECT COALESCE(SUM(t.amount),0) FROM $t t WHERE $w AND t.status = 'won' AND t.closed_at >= :ms", $p + ['ms' => $monthStart . ' 00:00:00']);
            $wonPrev = (float) $db->value("SELECT COALESCE(SUM(t.amount),0) FROM $t t WHERE $w AND t.status = 'won' AND t.closed_at BETWEEN :a AND :b", $p + ['a' => $lastMonthStart . ' 00:00:00', 'b' => $lastMonthEnd . ' 23:59:59']);
            $out[] = ['label' => 'Won this month', 'value' => money($won), 'delta' => self::delta($won, $wonPrev), 'icon' => 'trending-up', 'url' => url('/r/deals', ['view' => 'list'])];
        }
        if (self::canView('invoices')) {
            [$w, $p, $t] = self::scope('invoices');
            $collected = (float) $db->value("SELECT COALESCE(SUM(pay.amount),0) FROM payments pay JOIN invoices t ON t.id = pay.invoice_id WHERE $w AND pay.deleted_at IS NULL AND pay.paid_on >= :ms", $p + ['ms' => $monthStart]);
            $outstanding = $db->one("SELECT COALESCE(SUM(t.balance),0) AS bal, SUM(CASE WHEN t.status = 'Overdue' THEN 1 ELSE 0 END) AS overdue FROM $t t WHERE $w AND t.status IN ('Sent','Partially Paid','Overdue')", $p);
            $out[] = ['label' => 'Collected this month', 'value' => money($collected), 'icon' => 'wallet', 'url' => url('/r/payments')];
            $out[] = ['label' => 'Outstanding', 'value' => money($outstanding['bal']), 'sub' => (int) $outstanding['overdue'] . ' overdue', 'icon' => 'receipt', 'url' => url('/r/invoices', ['f' => 'status:in:Sent,Partially Paid,Overdue']), 'alert' => (int) $outstanding['overdue'] > 0];
        }
        if (self::canView('projects')) {
            [$w, $p, $t] = self::scope('projects');
            $active = (int) $db->value("SELECT COUNT(*) FROM $t t WHERE $w AND t.status = 'In Progress'", $p);
            $out[] = ['label' => 'Active projects', 'value' => (string) $active, 'icon' => 'folder-kanban', 'url' => url('/r/projects')];
        }
        if (self::canView('tickets')) {
            [$w, $p, $t] = self::scope('tickets');
            $open = $db->one("SELECT COUNT(*) AS c, SUM(CASE WHEN t.sla_status = 'Breached' THEN 1 ELSE 0 END) AS breached FROM $t t WHERE $w AND t.status NOT IN ('Resolved','Closed')", $p);
            $out[] = ['label' => 'Open tickets', 'value' => (string) (int) $open['c'], 'sub' => (int) $open['breached'] . ' SLA breached', 'icon' => 'life-buoy', 'url' => url('/r/tickets'), 'alert' => (int) $open['breached'] > 0];
        }
        return array_slice($out, 0, 6);
    }

    private static function delta(float $cur, float $prev): ?array
    {
        if ($prev <= 0) {
            return $cur > 0 ? ['value' => 'new', 'up' => true] : null;
        }
        $pct = (int) round(($cur - $prev) / $prev * 100);
        return ['value' => ($pct >= 0 ? '+' : '') . $pct . '%', 'up' => $pct >= 0];
    }

    /** Revenue (payments) vs invoiced per month for the last N months. */
    public static function revenueSeries(int $months = 12): array
    {
        if (!self::canView('invoices')) {
            return [];
        }
        $db = App::db();
        [$w, $p] = self::scope('invoices');
        $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $ym = $db->yearMonth('t.issue_date');
        $inv = [];
        foreach ($db->all("SELECT $ym AS m, SUM(t.total) AS s FROM invoices t WHERE $w AND t.status <> 'Cancelled' AND t.issue_date >= :from GROUP BY m", $p + ['from' => $from]) as $r) {
            $inv[$r['m']] = (float) $r['s'];
        }
        $ymp = $db->yearMonth('pay.paid_on');
        $paid = [];
        foreach ($db->all("SELECT $ymp AS m, SUM(pay.amount) AS s FROM payments pay JOIN invoices t ON t.id = pay.invoice_id WHERE $w AND pay.deleted_at IS NULL AND pay.paid_on >= :from GROUP BY m", $p + ['from' => $from]) as $r) {
            $paid[$r['m']] = (float) $r['s'];
        }
        $labels = $a = $b = [];
        for ($i = 0; $i < $months; $i++) {
            $k = date('Y-m', strtotime("$from +$i months"));
            $labels[] = date('M y', strtotime($k . '-01'));
            $a[] = round($inv[$k] ?? 0, 2);
            $b[] = round($paid[$k] ?? 0, 2);
        }
        return ['labels' => $labels, 'invoiced' => $a, 'collected' => $b];
    }

    public static function pipelineByStage(): array
    {
        if (!self::canView('deals')) {
            return [];
        }
        $db = App::db();
        [$w, $p] = self::scope('deals');
        $pipeline = $db->one('SELECT * FROM pipelines WHERE company_id = ? ORDER BY is_default DESC, sort, id LIMIT 1', [Auth::companyId()]);
        if (!$pipeline) {
            return [];
        }
        $rows = $db->all("SELECT s.name, s.color, s.sort, COUNT(t.id) AS c, COALESCE(SUM(t.amount),0) AS amt FROM pipeline_stages s LEFT JOIN deals t ON t.stage_id = s.id AND $w WHERE s.pipeline_id = :pid AND s.is_lost = 0 GROUP BY s.id, s.name, s.color, s.sort ORDER BY s.sort", $p + ['pid' => $pipeline['id']]);
        return ['labels' => array_column($rows, 'name'), 'amounts' => array_map('floatval', array_column($rows, 'amt')), 'counts' => array_map('intval', array_column($rows, 'c'))];
    }

    public static function leadsBySource(int $days = 90): array
    {
        if (!self::canView('leads')) {
            return [];
        }
        $db = App::db();
        [$w, $p] = self::scope('leads');
        $rows = $db->all("SELECT COALESCE(t.source, 'Unknown') AS src, COUNT(*) AS c, SUM(CASE WHEN t.status = 'Converted' THEN 1 ELSE 0 END) AS conv FROM leads t WHERE $w AND t.created_at >= :from GROUP BY src ORDER BY c DESC", $p + ['from' => gmdate('Y-m-d', strtotime("-$days days"))]);
        return ['labels' => array_column($rows, 'src'), 'counts' => array_map('intval', array_column($rows, 'c')), 'converted' => array_map('intval', array_column($rows, 'conv'))];
    }

    public static function tasksByStatus(): array
    {
        if (!self::canView('tasks')) {
            return [];
        }
        $db = App::db();
        [$w, $p] = self::scope('tasks');
        $rows = $db->all("SELECT t.status AS s, COUNT(*) AS c FROM tasks t WHERE $w GROUP BY t.status", $p);
        return ['labels' => array_column($rows, 's'), 'counts' => array_map('intval', array_column($rows, 'c'))];
    }

    public static function myTasks(int $limit = 8): array
    {
        if (!self::canView('tasks')) {
            return [];
        }
        $res = RecordService::for('tasks')->list(['filters' => [['field' => 'assigned_to', 'op' => 'me'], ['field' => 'status', 'op' => 'neq', 'value' => 'Done']], 'sort' => 'due_date', 'dir' => 'asc', 'per_page' => $limit]);
        return $res['rows'];
    }

    public static function upcomingEvents(int $limit = 5): array
    {
        if (!self::canView('events')) {
            return [];
        }
        $res = RecordService::for('events')->list(['filters' => [['field' => 'starts_at', 'op' => 'gte', 'value' => to_local_input(gmdate('Y-m-d H:i:s'))]], 'sort' => 'starts_at', 'dir' => 'asc', 'per_page' => $limit]);
        return $res['rows'];
    }

    // ───────────── Reports ─────────────

    public static function catalog(): array
    {
        return [
            'sales' => ['title' => 'Sales performance', 'desc' => 'Won deals and revenue by owner', 'module' => 'deals', 'icon' => 'trending-up'],
            'pipeline' => ['title' => 'Pipeline forecast', 'desc' => 'Open deals by stage with weighted value', 'module' => 'deals', 'icon' => 'handshake'],
            'aging' => ['title' => 'Invoice aging', 'desc' => 'Outstanding balances by age bucket and customer', 'module' => 'invoices', 'icon' => 'receipt'],
            'time' => ['title' => 'Time & utilization', 'desc' => 'Logged and billable hours by person and project', 'module' => 'time_entries', 'icon' => 'clock'],
            'tickets' => ['title' => 'Support SLA', 'desc' => 'Tickets by status, priority and SLA outcome', 'module' => 'tickets', 'icon' => 'life-buoy'],
            'leads' => ['title' => 'Lead conversion', 'desc' => 'Leads and conversion rate by source', 'module' => 'leads', 'icon' => 'target'],
            'expenses' => ['title' => 'Expenses', 'desc' => 'Spend by category and project', 'module' => 'expenses', 'icon' => 'wallet'],
            'profitability' => ['title' => 'Project profitability', 'desc' => 'Invoiced vs cost (time value + expenses) per project', 'module' => 'projects', 'icon' => 'chart-pie'],
        ];
    }

    /** @return array{columns:array, rows:array, chart:?array, totals:?array} */
    public static function run(string $key, string $from, string $to): array
    {
        $db = App::db();
        $fromDt = $from . ' 00:00:00';
        $toDt = $to . ' 23:59:59';
        switch ($key) {
            case 'sales':
                [$w, $p] = self::scope('deals');
                $rows = $db->all("SELECT COALESCE(u.name, 'Unassigned') AS owner, COUNT(*) AS won, COALESCE(SUM(t.amount),0) AS amount, COALESCE(AVG(t.amount),0) AS avg_deal FROM deals t LEFT JOIN users u ON u.id = t.assigned_to WHERE $w AND t.status = 'won' AND t.closed_at BETWEEN :a AND :b GROUP BY owner ORDER BY amount DESC", $p + ['a' => $fromDt, 'b' => $toDt]);
                $lost = [];
                foreach ($db->all("SELECT COALESCE(u.name, 'Unassigned') AS owner, COUNT(*) AS c FROM deals t LEFT JOIN users u ON u.id = t.assigned_to WHERE $w AND t.status = 'lost' AND t.closed_at BETWEEN :a AND :b GROUP BY owner", $p + ['a' => $fromDt, 'b' => $toDt]) as $l) {
                    $lost[$l['owner']] = (int) $l['c'];
                }
                $out = [];
                foreach ($rows as $r) {
                    $l = $lost[$r['owner']] ?? 0;
                    $out[] = [$r['owner'], (int) $r['won'], $l, ($r['won'] + $l) ? round($r['won'] / ($r['won'] + $l) * 100) . '%' : '—', money($r['amount']), money($r['avg_deal'])];
                }
                return ['columns' => ['Owner', 'Won', 'Lost', 'Win rate', 'Revenue', 'Avg. deal'], 'rows' => $out, 'chart' => ['type' => 'bar', 'labels' => array_column($rows, 'owner'), 'data' => array_map('floatval', array_column($rows, 'amount')), 'label' => 'Revenue']];
            case 'pipeline':
                [$w, $p] = self::scope('deals');
                $rows = $db->all("SELECT pl.name AS pipeline, s.name AS stage, COUNT(t.id) AS c, COALESCE(SUM(t.amount),0) AS amt, COALESCE(SUM(t.amount*t.probability/100),0) AS weighted FROM deals t JOIN pipeline_stages s ON s.id = t.stage_id JOIN pipelines pl ON pl.id = t.pipeline_id WHERE $w AND t.status = 'open' GROUP BY pl.name, s.name, s.sort ORDER BY pl.name, s.sort", $p);
                return ['columns' => ['Pipeline', 'Stage', 'Deals', 'Value', 'Weighted'], 'rows' => array_map(fn ($r) => [$r['pipeline'], $r['stage'], (int) $r['c'], money($r['amt']), money($r['weighted'])], $rows), 'chart' => ['type' => 'bar', 'labels' => array_column($rows, 'stage'), 'data' => array_map('floatval', array_column($rows, 'weighted')), 'label' => 'Weighted value']];
            case 'aging':
                [$w, $p] = self::scope('invoices');
                $today = date('Y-m-d');
                $rows = $db->all("SELECT c.name AS customer, t.due_date, t.balance FROM invoices t LEFT JOIN customers c ON c.id = t.customer_id WHERE $w AND t.status IN ('Sent','Partially Paid','Overdue') AND t.balance > 0", $p);
                $agg = [];
                $buckets = ['Current' => 0, '1–30' => 0, '31–60' => 0, '61–90' => 0, '90+' => 0];
                foreach ($rows as $r) {
                    $days = $r['due_date'] ? (int) floor((strtotime($today) - strtotime($r['due_date'])) / 86400) : 0;
                    $b = $days <= 0 ? 'Current' : ($days <= 30 ? '1–30' : ($days <= 60 ? '31–60' : ($days <= 90 ? '61–90' : '90+')));
                    $name = $r['customer'] ?? '—';
                    $agg[$name] ??= array_fill_keys(array_keys($buckets), 0.0);
                    $agg[$name][$b] += (float) $r['balance'];
                    $buckets[$b] += (float) $r['balance'];
                }
                $out = [];
                foreach ($agg as $name => $bk) {
                    $out[] = array_merge([$name], array_map(fn ($v) => money($v), array_values($bk)), [money(array_sum($bk))]);
                }
                return ['columns' => array_merge(['Customer'], array_keys($buckets), ['Total']), 'rows' => $out, 'chart' => ['type' => 'bar', 'labels' => array_keys($buckets), 'data' => array_values($buckets), 'label' => 'Outstanding'], 'totals' => array_merge(['Total'], array_map(fn ($v) => money($v), array_values($buckets)), [money(array_sum($buckets))])];
            case 'time':
                [$w, $p] = self::scope('time_entries');
                $rows = $db->all("SELECT COALESCE(u.name,'—') AS person, COALESCE(pr.name,'No project') AS project, SUM(t.minutes) AS mins, SUM(CASE WHEN t.is_billable = 1 THEN t.minutes ELSE 0 END) AS bill, SUM(CASE WHEN t.is_billable = 1 THEN t.minutes * COALESCE(t.hourly_rate,0) / 60 ELSE 0 END) AS val FROM time_entries t LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN projects pr ON pr.id = t.project_id WHERE $w AND t.entry_date BETWEEN :a AND :b GROUP BY person, project ORDER BY person, mins DESC", $p + ['a' => $from, 'b' => $to]);
                $byPerson = [];
                foreach ($rows as $r) {
                    $byPerson[$r['person']] = ($byPerson[$r['person']] ?? 0) + (int) $r['mins'] / 60;
                }
                return ['columns' => ['Person', 'Project', 'Hours', 'Billable hours', 'Billable %', 'Billable value'], 'rows' => array_map(fn ($r) => [$r['person'], $r['project'], round($r['mins'] / 60, 1), round($r['bill'] / 60, 1), $r['mins'] ? round($r['bill'] / $r['mins'] * 100) . '%' : '—', money($r['val'])], $rows), 'chart' => ['type' => 'bar', 'labels' => array_keys($byPerson), 'data' => array_map(fn ($v) => round($v, 1), array_values($byPerson)), 'label' => 'Hours']];
            case 'tickets':
                [$w, $p] = self::scope('tickets');
                $rows = $db->all("SELECT t.priority, COUNT(*) AS total, SUM(CASE WHEN t.status IN ('Resolved','Closed') THEN 1 ELSE 0 END) AS resolved, SUM(CASE WHEN t.sla_status = 'Met' THEN 1 ELSE 0 END) AS met, SUM(CASE WHEN t.sla_status = 'Breached' THEN 1 ELSE 0 END) AS breached, AVG(t.satisfaction) AS csat FROM tickets t WHERE $w AND t.created_at BETWEEN :a AND :b GROUP BY t.priority", $p + ['a' => $fromDt, 'b' => $toDt]);
                return ['columns' => ['Priority', 'Tickets', 'Resolved', 'SLA met', 'SLA breached', 'Avg. CSAT'], 'rows' => array_map(fn ($r) => [$r['priority'], (int) $r['total'], (int) $r['resolved'], (int) $r['met'], (int) $r['breached'], $r['csat'] ? round((float) $r['csat'], 1) : '—'], $rows), 'chart' => ['type' => 'bar', 'labels' => array_column($rows, 'priority'), 'data' => array_map('intval', array_column($rows, 'total')), 'label' => 'Tickets']];
            case 'leads':
                [$w, $p] = self::scope('leads');
                $rows = $db->all("SELECT COALESCE(t.source,'Unknown') AS src, COUNT(*) AS c, SUM(CASE WHEN t.status = 'Converted' THEN 1 ELSE 0 END) AS conv, SUM(CASE WHEN t.status = 'Lost' THEN 1 ELSE 0 END) AS lost, COALESCE(SUM(t.estimated_value),0) AS val FROM leads t WHERE $w AND t.created_at BETWEEN :a AND :b GROUP BY src ORDER BY c DESC", $p + ['a' => $fromDt, 'b' => $toDt]);
                return ['columns' => ['Source', 'Leads', 'Converted', 'Lost', 'Conversion', 'Est. value'], 'rows' => array_map(fn ($r) => [$r['src'], (int) $r['c'], (int) $r['conv'], (int) $r['lost'], $r['c'] ? round($r['conv'] / $r['c'] * 100) . '%' : '—', money($r['val'])], $rows), 'chart' => ['type' => 'doughnut', 'labels' => array_column($rows, 'src'), 'data' => array_map('intval', array_column($rows, 'c')), 'label' => 'Leads']];
            case 'expenses':
                [$w, $p] = self::scope('expenses');
                $rows = $db->all("SELECT COALESCE(t.category,'Uncategorised') AS cat, COUNT(*) AS c, COALESCE(SUM(t.amount),0) AS amt, COALESCE(SUM(CASE WHEN t.is_billable = 1 THEN t.amount ELSE 0 END),0) AS billable FROM expenses t WHERE $w AND t.status <> 'Rejected' AND t.expense_date BETWEEN :a AND :b GROUP BY cat ORDER BY amt DESC", $p + ['a' => $from, 'b' => $to]);
                return ['columns' => ['Category', 'Entries', 'Amount', 'Billable'], 'rows' => array_map(fn ($r) => [$r['cat'], (int) $r['c'], money($r['amt']), money($r['billable'])], $rows), 'chart' => ['type' => 'doughnut', 'labels' => array_column($rows, 'cat'), 'data' => array_map('floatval', array_column($rows, 'amt')), 'label' => 'Spend'], 'totals' => ['Total', array_sum(array_column($rows, 'c')), money(array_sum(array_column($rows, 'amt'))), money(array_sum(array_column($rows, 'billable')))]];
            case 'profitability':
                [$w, $p] = self::scope('projects');
                $rows = $db->all("SELECT t.id, t.name, t.budget FROM projects t WHERE $w ORDER BY t.name", $p);
                $out = [];
                foreach ($rows as $r) {
                    $s = ProjectService::stats((int) $r['id']);
                    $cost = $s['time_value'] + $s['expenses'];
                    $margin = $s['invoiced'] - $cost;
                    $out[] = [$r['name'], money($r['budget']), money($s['invoiced']), money($s['time_value']), money($s['expenses']), money($margin), $s['invoiced'] > 0 ? round($margin / $s['invoiced'] * 100) . '%' : '—'];
                }
                return ['columns' => ['Project', 'Budget', 'Invoiced', 'Time value', 'Expenses', 'Margin', 'Margin %'], 'rows' => $out, 'chart' => null];
        }
        return ['columns' => [], 'rows' => [], 'chart' => null];
    }
}

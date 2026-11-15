<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Mailer;
use App\Core\Xlsx;

/**
 * Custom reports: group any module by a field (or a date bucket) and aggregate count/sum/avg/min/max of a
 * numeric field, with filters. Queries go through RecordService::baseWhere(), so tenant isolation and the
 * viewer's record scope (own/team/all) always apply — a scheduled report runs with its creator's permissions.
 */
final class ReportBuilderService
{
    public const METRICS = ['count' => 'Count', 'sum' => 'Sum', 'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum'];
    public const BUCKETS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year'];
    public const CHARTS = ['bar' => 'Bar', 'line' => 'Line', 'pie' => 'Pie', 'doughnut' => 'Doughnut', 'none' => 'Table only'];
    private const GROUPABLE = ['select', 'user', 'relation', 'stage', 'pipeline', 'bool', 'currency', 'date', 'datetime', 'tax', 'text', 'email'];
    private const NUMERIC = ['money', 'decimal', 'number', 'percent', 'duration'];

    /** Fields that can be grouped / aggregated for an entity (respecting sensitive-field permissions). */
    public static function fieldsFor(string $entity): array
    {
        $def = EntityRegistry::get($entity);
        $group = [];
        $num = [];
        foreach (EntityRegistry::visibleFields($def) as $n => $f) {
            if (in_array($f['type'], self::GROUPABLE, true) && $f['type'] !== 'hidden') {
                $group[$n] = ['label' => $f['label'], 'type' => $f['type']];
            }
            if (in_array($f['type'], self::NUMERIC, true) && empty($f['custom'])) {
                $num[$n] = $f['label'];
            }
        }
        $group['created_at'] = ['label' => 'Created', 'type' => 'datetime'];
        return ['group' => $group, 'numeric' => $num];
    }

    public static function normalize(array $in): array
    {
        $entity = (string) ($in['entity'] ?? '');
        if (!EntityRegistry::exists($entity) || !can(EntityRegistry::get($entity)['module'], 'view')) {
            throw new HttpException(422, 'Choose a module you can view.');
        }
        $f = self::fieldsFor($entity);
        $group = (string) ($in['group_by'] ?? '');
        $metric = isset(self::METRICS[$in['metric'] ?? '']) ? $in['metric'] : 'count';
        $mfield = (string) ($in['metric_field'] ?? '');
        if ($metric !== 'count' && !isset($f['numeric'][$mfield])) {
            throw new HttpException(422, 'Choose a number field to ' . strtolower(self::METRICS[$metric]) . '.');
        }
        $filters = [];
        foreach (array_slice((array) ($in['filters'] ?? []), 0, 10) as $x) {
            if (is_array($x) && !empty($x['field']) && in_array($x['op'] ?? '', RecordService::FILTER_OPS, true)) {
                $filters[] = ['field' => (string) $x['field'], 'op' => (string) $x['op'], 'value' => is_scalar($x['value'] ?? null) ? (string) $x['value'] : '', 'value2' => is_scalar($x['value2'] ?? null) ? (string) $x['value2'] : ''];
            }
        }
        return [
            'entity' => $entity,
            'group_by' => isset($f['group'][$group]) ? $group : '',
            'bucket' => isset(self::BUCKETS[$in['bucket'] ?? '']) ? $in['bucket'] : 'month',
            'metric' => $metric, 'metric_field' => $metric === 'count' ? '' : $mfield,
            'filters' => $filters,
            'chart' => isset(self::CHARTS[$in['chart'] ?? '']) ? $in['chart'] : 'bar',
            'sort' => in_array($in['sort'] ?? '', ['value_desc', 'value_asc', 'label_asc'], true) ? $in['sort'] : 'value_desc',
            'limit' => max(5, min(100, (int) ($in['limit'] ?? 25))),
        ];
    }

    private static function bucketExpr(string $col, string $bucket): string
    {
        $sqlite = App::db()->driver === 'sqlite';
        return match ($bucket) {
            'day' => $sqlite ? "substr($col, 1, 10)" : "DATE_FORMAT($col, '%Y-%m-%d')",
            'week' => $sqlite ? "strftime('%Y-W%W', $col)" : "DATE_FORMAT($col, '%x-W%v')",
            'quarter' => $sqlite ? "(substr($col, 1, 4) || '-Q' || ((CAST(substr($col, 6, 2) AS INTEGER) + 2) / 3))" : "CONCAT(YEAR($col), '-Q', QUARTER($col))",
            'year' => $sqlite ? "substr($col, 1, 4)" : "DATE_FORMAT($col, '%Y')",
            default => $sqlite ? "substr($col, 1, 7)" : "DATE_FORMAT($col, '%Y-%m')",
        };
    }

    /** @return array{rows: array<int, array{label:string, value:float}>, total: float, metric_label: string, group_label: string} */
    public static function run(array $cfg): array
    {
        $svc = RecordService::for($cfg['entity']);
        $db = App::db();
        [$where, $params] = $svc->baseWhere('view');
        foreach ($cfg['filters'] as $i => $f) {
            $svc->applyFilter($f, 'rb' . $i, $where, $params);
        }
        $defs = $svc->def['fields'] + ['created_at' => ['type' => 'datetime', 'label' => 'Created']];
        $metricSql = 'COUNT(*)';
        if ($cfg['metric'] !== 'count') {
            $metricSql = strtoupper($cfg['metric']) . '(t.' . $db->ident($cfg['metric_field']) . ')';
        }
        $whereSql = implode(' AND ', $where);
        $table = $db->ident($svc->table);
        $groupLabel = 'All records';
        if ($cfg['group_by'] === '') {
            $value = (float) $db->value("SELECT $metricSql FROM $table t WHERE $whereSql", $params);
            return ['rows' => [['label' => 'Total', 'value' => round($value, 2)]], 'total' => round($value, 2), 'metric_label' => self::metricLabel($cfg, $defs), 'group_label' => $groupLabel];
        }
        $gf = $defs[$cfg['group_by']];
        $groupLabel = $gf['label'];
        $col = !empty($gf['custom']) ? $db->jsonGet('t.custom', $cfg['group_by']) : 't.' . $db->ident($cfg['group_by']);
        $gexpr = in_array($gf['type'], ['date', 'datetime'], true) ? self::bucketExpr($col, $cfg['bucket']) : $col;
        $order = match ($cfg['sort']) { 'value_asc' => 'v ASC', 'label_asc' => 'g ASC', default => 'v DESC' };
        if (in_array($gf['type'], ['date', 'datetime'], true)) {
            $order = 'g ASC';
        }
        $rows = $db->all("SELECT $gexpr AS g, $metricSql AS v FROM $table t WHERE $whereSql GROUP BY $gexpr ORDER BY $order LIMIT " . (int) $cfg['limit'], $params);
        $labels = self::labels($gf, array_column($rows, 'g'), $cfg['group_by']);
        $out = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $v = round((float) $row['v'], 2);
            $total += $v;
            $out[] = ['label' => $labels[(string) $row['g']] ?? ((string) $row['g'] === '' ? '(empty)' : (string) $row['g']), 'value' => $v];
        }
        return ['rows' => $out, 'total' => round($total, 2), 'metric_label' => self::metricLabel($cfg, $defs), 'group_label' => $groupLabel];
    }

    private static function metricLabel(array $cfg, array $defs): string
    {
        return $cfg['metric'] === 'count' ? 'Records' : self::METRICS[$cfg['metric']] . ' of ' . ($defs[$cfg['metric_field']]['label'] ?? $cfg['metric_field']);
    }

    private static function labels(array $f, array $values, string $name): array
    {
        $db = App::db();
        $ids = array_values(array_filter(array_map('intval', $values)));
        $in = $ids ? implode(',', $ids) : '0';
        $map = [];
        switch ($f['type']) {
            case 'user':
                foreach ($db->all("SELECT id, name FROM users WHERE id IN ($in)") as $u) { $map[(string) $u['id']] = $u['name']; }
                break;
            case 'stage':
                foreach ($db->all("SELECT id, name FROM pipeline_stages WHERE id IN ($in)") as $s) { $map[(string) $s['id']] = $s['name']; }
                break;
            case 'pipeline':
                foreach ($db->all("SELECT id, name FROM pipelines WHERE id IN ($in) AND company_id = ?", [Auth::companyId()]) as $s) { $map[(string) $s['id']] = $s['name']; }
                break;
            case 'relation':
                $t = EntityRegistry::get($f['relation']);
                foreach ($db->all('SELECT id, ' . $db->ident($t['title']) . " AS n FROM " . $db->ident($t['table']) . " WHERE id IN ($in) AND company_id = ?", [Auth::companyId()]) as $x) { $map[(string) $x['id']] = (string) $x['n']; }
                break;
            case 'bool':
                $map = ['1' => 'Yes', '0' => 'No', '' => 'No'];
                break;
            case 'select':
                foreach (EntityRegistry::options($f + ['name' => $name]) as $k => $l) { $map[(string) $k] = (string) $l; }
                break;
        }
        return $map;
    }

    public static function csv(array $cfg, array $res): string
    {
        return Xlsx::csv([$res['group_label'], $res['metric_label']], array_map(fn ($r) => [$r['label'], $r['value']], $res['rows']));
    }

    /** Cron (daily): e-mails scheduled reports, each run with its creator's permissions. */
    public static function sendScheduled(): int
    {
        $db = App::db();
        $sent = 0;
        $dow = (int) date('N');
        $dom = (int) date('j');
        foreach ($db->all("SELECT * FROM custom_reports WHERE schedule <> 'none' AND recipients IS NOT NULL") as $rep) {
            $due = match ($rep['schedule']) { 'daily' => true, 'weekly' => $dow === 1, 'monthly' => $dom === 1, default => false };
            if (!$due || ($rep['last_sent_at'] && substr((string) $rep['last_sent_at'], 0, 10) === gmdate('Y-m-d'))) {
                continue;
            }
            $ok = Auth::actingAs((int) $rep['created_by'], (int) $rep['company_id'], function () use ($rep) {
                $cfg = self::normalize(json_decode((string) $rep['config'], true) ?: []);
                $res = self::run($cfg);
                $rowsHtml = '';
                foreach ($res['rows'] as $row) {
                    $rowsHtml .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee">' . e($row['label']) . '</td><td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right">' . e(number_format($row['value'], 2)) . '</td></tr>';
                }
                $html = '<h2 style="margin:0 0 4px">' . e($rep['name']) . '</h2><p style="color:#6b7280;margin:0 0 14px">' . e(date('d M Y')) . '</p>'
                    . '<table style="border-collapse:collapse;width:100%;font-size:13px"><tr><th style="text-align:left;padding:6px 10px;border-bottom:2px solid #ddd">' . e($res['group_label']) . '</th><th style="text-align:right;padding:6px 10px;border-bottom:2px solid #ddd">' . e($res['metric_label']) . '</th></tr>' . $rowsHtml . '</table>'
                    . '<p style="margin-top:14px"><a href="' . e(full_url('/reports/custom/' . $rep['id'])) . '">Open in ' . e((string) setting('app.name', 'NextaCRM')) . '</a></p>';
                $att = [['name' => preg_replace('/[^A-Za-z0-9_\-]+/', '-', $rep['name']) . '.csv', 'mime' => 'text/csv', 'content_b64' => base64_encode(self::csv($cfg, $res))]];
                foreach (array_slice(array_filter(array_map('trim', explode(',', (string) $rep['recipients'])), fn ($x) => filter_var($x, FILTER_VALIDATE_EMAIL)), 0, 10) as $to) {
                    Mailer::queue($to, 'Report: ' . $rep['name'], $html, $att);
                }
                return true;
            });
            if ($ok) {
                $db->update('custom_reports', ['last_sent_at' => DB::now()], ['id' => $rep['id']]);
                $sent++;
            }
        }
        return $sent;
    }
}

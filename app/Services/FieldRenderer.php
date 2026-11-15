<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

/**
 * Renders field values for display (HTML-escaped) and export (plain text).
 */
final class FieldRenderer
{
    public static function html(array $f, array $row, ?RecordService $svc = null): string
    {
        $name = $f['name'];
        $v = $row[$name] ?? null;
        if ($v === null || $v === '') {
            return '<span class="text-faint">—</span>';
        }
        $label = $row['_labels'][$name] ?? null;
        $currency = $row['currency'] ?? $row['salary_currency'] ?? null;
        switch ($f['type']) {
            case 'relation':
                $href = url('/r/' . $f['relation'] . '/' . (int) $v);
                return '<a href="' . e($href) . '" class="link" @click.stop>' . e($label ?? ('#' . $v)) . '</a>';
            case 'user':
                $u = LookupService::users([(int) $v])[(int) $v] ?? null;
                if (!$u) {
                    return '<span class="text-faint">—</span>';
                }
                return '<span class="inline-flex items-center gap-1.5 whitespace-nowrap">' . self::avatar($u['name'], $u['hue'], 'size-5 text-[9px]') . '<span>' . e($u['name']) . '</span></span>';
            case 'stage':
                $s = $row['_stage'] ?? null;
                return badge($label ?? (string) $v, $s['color'] ?? 'blue');
            case 'select':
                $opts = EntityRegistry::options($f);
                $text = $opts[(string) $v] ?? (string) $v;
                if (in_array($name, ['status', 'priority', 'rating', 'sla_status', 'type'], true) || !empty($f['badge'])) {
                    return badge($text, $name === 'type' ? 'slate' : null);
                }
                return e($text);
            case 'multiselect':
            case 'tags':
                $out = '';
                foreach (array_filter(array_map('trim', explode(',', (string) $v))) as $tag) {
                    $out .= '<span class="tag" style="--h:' . hue_for($tag) . '">' . e($tag) . '</span> ';
                }
                return '<span class="inline-flex flex-wrap gap-1">' . $out . '</span>';
            case 'money':
                return '<span class="tabular-nums whitespace-nowrap">' . e(money($v, $currency)) . '</span>';
            case 'decimal':
                return '<span class="tabular-nums">' . e(rtrim(rtrim(number_format((float) $v, 2), '0'), '.')) . '</span>';
            case 'number':
                return '<span class="tabular-nums">' . e(number_format((int) $v)) . '</span>';
            case 'percent':
                $p = max(0, min(100, (int) $v));
                return '<span class="inline-flex items-center gap-2 min-w-24"><span class="progress w-16"><span style="width:' . $p . '%"></span></span><span class="tabular-nums text-xs text-muted">' . $p . '%</span></span>';
            case 'duration':
                return '<span class="tabular-nums">' . e(self::duration((int) $v)) . '</span>';
            case 'date':
                $overdue = in_array($name, ['due_date', 'follow_up_date', 'expected_close', 'next_follow_up', 'end_date'], true) && $v < date('Y-m-d') && !self::isClosed($row);
                return '<span class="whitespace-nowrap ' . ($overdue ? 'text-red-600 dark:text-red-400 font-medium' : '') . '">' . e(fmt_date((string) $v)) . '</span>';
            case 'datetime':
                $overdue = in_array($name, ['resolution_due', 'first_response_due'], true) && strtotime($v . ' UTC') < time() && !self::isClosed($row);
                return '<span class="whitespace-nowrap ' . ($overdue ? 'text-red-600 dark:text-red-400 font-medium' : '') . '" title="' . e(fmt_datetime((string) $v)) . '">' . e(fmt_datetime((string) $v)) . '</span>';
            case 'bool':
                return $v ? '<span class="inline-flex items-center text-emerald-600 dark:text-emerald-400">' . icon('check', 'size-4') . '</span>' : '<span class="text-faint">No</span>';
            case 'email':
                return '<a class="link" href="mailto:' . e($v) . '" @click.stop>' . e($v) . '</a>';
            case 'phone':
                $tel = preg_replace('/[^0-9+]/', '', (string) $v);
                return '<a class="link whitespace-nowrap" href="tel:' . e($tel) . '" @click.stop>' . e($v) . '</a>';
            case 'url':
                return '<a class="link" href="' . e($v) . '" target="_blank" rel="noopener noreferrer nofollow" @click.stop>' . e(str_limit(preg_replace('#^https?://(www\.)?#', '', (string) $v), 40)) . '</a>';
            case 'textarea':
                return '<span class="whitespace-pre-line">' . nl2br_safe((string) $v) . '</span>';
            case 'currency':
                return e($v);
            case 'tax':
            case 'pipeline':
                return e($label ?? $v);
            case 'hidden':
                return e($v);
        }
        return e(is_scalar($v) ? (string) $v : '');
    }

    public static function plain(array $f, mixed $v, ?RecordService $svc = null, array $row = []): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $label = $row['_labels'][$f['name']] ?? null;
        return match ($f['type']) {
            'relation' => (string) ($label ?? (LookupService::titles($f['relation'], [(int) $v])[(int) $v] ?? $v)),
            'user' => (string) ($label ?? (LookupService::users([(int) $v])[(int) $v]['name'] ?? $v)),
            'stage' => (string) ($label ?? (\App\Core\App::db()->value('SELECT name FROM pipeline_stages WHERE id = ?', [(int) $v]) ?? $v)),
            'pipeline' => (string) ($label ?? (\App\Core\App::db()->value('SELECT name FROM pipelines WHERE id = ?', [(int) $v]) ?? $v)),
            'select' => EntityRegistry::options($f)[(string) $v] ?? (string) $v,
            'bool' => $v ? 'Yes' : 'No',
            'datetime' => fmt_datetime((string) $v, 'Y-m-d H:i'),
            'duration' => self::duration((int) $v),
            'money', 'decimal' => number_format((float) $v, 2, '.', ''),
            default => is_scalar($v) ? (string) $v : '',
        };
    }

    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? sprintf('%dh %02dm', $h, $m) : $m . 'm';
    }

    private static function isClosed(array $row): bool
    {
        $s = strtolower((string) ($row['status'] ?? ''));
        return in_array($s, ['done', 'completed', 'paid', 'won', 'lost', 'closed', 'resolved', 'converted', 'cancelled', 'expired', 'terminated', 'accepted', 'invoiced'], true);
    }

    public static function avatar(string $name, int $hue, string $class = 'size-7 text-[11px]'): string
    {
        return '<span class="avatar ' . e($class) . '" style="--h:' . $hue . '" title="' . e($name) . '">' . e(initials($name)) . '</span>';
    }
}

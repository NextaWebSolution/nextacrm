<?php
declare(strict_types=1);
defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Permissions;
use App\Core\Settings;

/** HTML-escape any value for safe output in templates. */
function e(mixed $v): string
{
    if ($v === null || is_array($v)) {
        return '';
    }
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON for embedding inside HTML attributes (Alpine x-data) — safe against breaking out. */
function ej(mixed $v): string
{
    return e(json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

function url(string $path = '/', array $query = []): string
{
    $base = App::request()->basePath;
    $u = $base . '/' . ltrim($path, '/');
    if ($u === '') {
        $u = '/';
    }
    return $query ? $u . '?' . http_build_query($query) : $u;
}

function full_url(string $path = '/'): string
{
    return App::request()->baseUrl() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = NX_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : App::version();
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function method_field(string $m): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($m)) . '">';
}

function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

function user(): ?array
{
    return Auth::user();
}

function can(string $module, string $action = 'view', ?array $record = null): bool
{
    return Permissions::can($module, $action, $record);
}

/** Translation hook (English strings are the keys). */
function __(string $text, array $replace = []): string
{
    static $lines = null;
    if ($lines === null) {
        $lang = (string) (App::config('locale') ?: 'en');
        $file = NX_ROOT . '/app/Lang/' . preg_replace('/[^a-z_]/i', '', $lang) . '.php';
        $lines = is_file($file) ? (array) require $file : [];
    }
    $t = $lines[$text] ?? $text;
    foreach ($replace as $k => $v) {
        $t = str_replace(':' . $k, (string) $v, $t);
    }
    return $t;
}

function icon(string $name, string $class = 'size-4'): string
{
    static $icons = null;
    $icons ??= require NX_ROOT . '/app/Core/icons.php';
    $alias = [
        'trash-2' => 'trash', 'filter' => 'funnel', 'alert' => 'circle-exclamation-point', 'warning' => 'triangle-exclamation-point',
        'help' => 'circle-question-mark', 'home' => 'house', 'more' => 'ellipsis', 'more-v' => 'ellipsis-vertical', 'history' => 'undo-2',
        'building-2' => 'building', 'chart' => 'chart-column', 'dashboard' => 'layout-dashboard', 'spinner' => 'loader-circle',
    ];
    $name = $alias[$name] ?? $name;
    $inner = $icons[$name] ?? $icons['circle-dot'];
    return '<svg xmlns="http://www.w3.org/2000/svg" class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

function user_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz) {
        return $tz;
    }
    $name = (string) (Auth::user()['timezone'] ?? App::config('timezone', 'UTC'));
    try {
        return $tz = new DateTimeZone($name ?: 'UTC');
    } catch (\Throwable) {
        return $tz = new DateTimeZone('UTC');
    }
}

/** Format a UTC datetime string in the user's timezone. */
function fmt_datetime(?string $utc, ?string $format = null): string
{
    if (!$utc) {
        return '';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(user_tz())->format($format ?? (setting('app.date_format', 'd M Y') . ', ' . setting('app.time_format', 'H:i')));
    } catch (\Throwable) {
        return e($utc);
    }
}

function fmt_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date((string) setting('app.date_format', 'd M Y'), $ts) : '';
}

/** Convert a local (user tz) datetime-local input value to UTC storage format. */
function to_utc(?string $local): ?string
{
    if (!$local) {
        return null;
    }
    try {
        $d = new DateTimeImmutable($local, user_tz());
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (\Throwable) {
        return null;
    }
}

/** UTC storage value → value for <input type="datetime-local">. */
function to_local_input(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(user_tz())->format('Y-m-d\TH:i');
    } catch (\Throwable) {
        return '';
    }
}

function time_ago(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    $diff = time() - (int) strtotime($utc . ' UTC');
    $future = $diff < 0;
    $diff = abs($diff);
    $s = match (true) {
        $diff < 60 => 'just now',
        $diff < 3600 => intdiv($diff, 60) . 'm',
        $diff < 86400 => intdiv($diff, 3600) . 'h',
        $diff < 604800 => intdiv($diff, 86400) . 'd',
        default => fmt_datetime($utc, (string) setting('app.date_format', 'd M Y')),
    };
    if ($s === 'just now' || $diff >= 604800) {
        return $s;
    }
    return $future ? 'in ' . $s : $s . ' ago';
}

function currency_symbol(string $code): string
{
    return [
        'INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'AUD' => 'A$', 'CAD' => 'C$', 'SGD' => 'S$',
        'JPY' => '¥', 'CNY' => '¥', 'SAR' => 'SAR ', 'ZAR' => 'R', 'NZD' => 'NZ$', 'CHF' => 'CHF ', 'BDT' => '৳', 'NPR' => 'Rs ', 'LKR' => 'Rs ',
    ][strtoupper($code)] ?? strtoupper($code) . ' ';
}

function money(mixed $amount, ?string $currency = null, bool $symbol = true): string
{
    $currency = $currency ?: \App\Services\CompanyService::currentCurrency();
    $n = (float) $amount;
    if (strtoupper($currency) === 'INR') {
        $formatted = indian_format($n);
    } else {
        $formatted = number_format($n, 2);
    }
    return ($symbol ? currency_symbol($currency) : '') . $formatted;
}

function indian_format(float $n): string
{
    $neg = $n < 0;
    $n = abs($n);
    $whole = (string) floor($n);
    $dec = str_pad((string) round(($n - floor($n)) * 100), 2, '0', STR_PAD_LEFT);
    if ($dec === '100') {
        $whole = (string) ((int) $whole + 1);
        $dec = '00';
    }
    if (strlen($whole) > 3) {
        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $whole = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . $whole . '.' . $dec;
}

function initials(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $i = mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '');
    return mb_strtoupper($i);
}

/** Stable pleasant color for avatars/tags from a string. */
function hue_for(string $s): int
{
    return (int) (hexdec(substr(md5($s), 0, 4)) % 360);
}

function old(string $key, mixed $default = ''): mixed
{
    $old = \App\Core\Session::get('_old', []);
    return $old[$key] ?? $default;
}

/** Render plain text safely with line breaks and auto-linked URLs. */
function nl2br_safe(?string $text): string
{
    $html = e($text);
    $html = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="noopener noreferrer nofollow" class="text-primary-600 underline">$1</a>', $html) ?? $html;
    return nl2br($html);
}

function status_color(?string $status): string
{
    $s = mb_strtolower((string) $status);
    return match (true) {
        in_array($s, ['won', 'paid', 'done', 'completed', 'resolved', 'closed', 'accepted', 'approved', 'active', 'converted', 'published', 'sent ✓', 'success'], true) => 'green',
        in_array($s, ['lost', 'overdue', 'cancelled', 'declined', 'rejected', 'blocked', 'urgent', 'failed', 'expired', 'terminated', 'breached'], true) => 'red',
        in_array($s, ['in progress', 'contacted', 'sent', 'partially paid', 'proposal sent', 'negotiation', 'in review', 'high', 'pending', 'open', 'qualified', 'on hold', 'waiting on customer', 'hot'], true) => 'amber',
        in_array($s, ['new', 'draft', 'to do', 'not started', 'low', 'cold', 'inactive'], true) => 'slate',
        default => 'blue',
    };
}

function badge(?string $text, ?string $color = null): string
{
    if ($text === null || $text === '') {
        return '';
    }
    $c = $color ?? status_color($text);
    return '<span class="badge badge-' . e($c) . '">' . e($text) . '</span>';
}

function module_enabled(string $module): bool
{
    return !in_array($module, (array) setting('app.modules_disabled', []), true);
}

function str_limit(?string $s, int $n = 80): string
{
    $s = (string) $s;
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

/**
 * Safe Markdown subset for knowledge-base articles: everything is HTML-escaped first, then a fixed set of
 * patterns is converted (#/##/### headings, **bold**, _italic_, `code`, ``` blocks, - / 1. lists, [text](https://url)).
 * Only http(s) and relative links are produced, so no script URLs can be injected.
 */
function markdown_safe(?string $md): string
{
    $md = str_replace("\r", '', (string) $md);
    $blocks = preg_split('/\n```.*?\n(.*?)\n```/s', "\n" . $md, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$md];
    $html = '';
    foreach ($blocks as $i => $block) {
        if ($i % 2 === 1) {
            $html .= '<pre class="kb-code"><code>' . e($block) . '</code></pre>';
            continue;
        }
        $lines = explode("\n", e(trim($block, "\n")));
        $list = null;
        $para = [];
        $flush = function () use (&$para, &$html) {
            if ($para) {
                $html .= '<p>' . implode('<br>', $para) . '</p>';
                $para = [];
            }
        };
        foreach ($lines as $line) {
            $inline = function (string $t): string {
                $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t) ?? $t;
                $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t) ?? $t;
                $t = preg_replace('/(?<![\w])_([^_]+)_(?![\w])/', '<em>$1</em>', $t) ?? $t;
                return preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)"<>]+)\)/', fn ($m) => '<a href="' . $m[2] . '" rel="noopener nofollow" target="' . (str_starts_with($m[2], '/') ? '_self' : '_blank') . '">' . $m[1] . '</a>', $t) ?? $t;
            };
            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $flush();
                if ($list) { $html .= "</$list>"; $list = null; }
                $lvl = strlen($m[1]) + 1;
                $html .= "<h$lvl>" . $inline($m[2]) . "</h$lvl>";
            } elseif (preg_match('/^\s*([-*]|\d+\.)\s+(.+)$/', $line, $m)) {
                $flush();
                $type = ctype_digit(rtrim($m[1], '.')) ? 'ol' : 'ul';
                if ($list !== $type) {
                    if ($list) { $html .= "</$list>"; }
                    $html .= "<$type>";
                    $list = $type;
                }
                $html .= '<li>' . $inline($m[2]) . '</li>';
            } elseif (trim($line) === '') {
                $flush();
                if ($list) { $html .= "</$list>"; $list = null; }
            } else {
                if ($list) { $html .= "</$list>"; $list = null; }
                $para[] = $inline($line);
            }
        }
        $flush();
        if ($list) { $html .= "</$list>"; }
    }
    return $html;
}

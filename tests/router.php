<?php
// Emulates the .htaccess rules for `php -S` (test only).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(app|config|storage|vendor|docs|tools|modules|examples|tests)(/|$)#', $path)) { http_response_code(403); exit('Forbidden'); }
// Emulate Apache: reject dot-segments, only serve real files inside /assets.
if (str_contains($path, '/..') || str_contains($path, '/./')) { http_response_code(400); exit; }
if (str_starts_with($path, '/assets/') && is_file((getenv('NX_SITE') ?: dirname(__DIR__)) . '' . $path)) { return false; }
if ($path === '/cron.php') { $_SERVER['SCRIPT_NAME'] = '/cron.php'; require (getenv('NX_SITE') ?: dirname(__DIR__)) . '/cron.php'; return true; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require (getenv('NX_SITE') ?: dirname(__DIR__)) . '/index.php';

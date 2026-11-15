<?php
/**
 * NextaCRM scheduler.
 *
 * cPanel → Cron Jobs → every 5 minutes:
 *     php /home/USER/public_html/cron.php >/dev/null 2>&1
 *
 * Hosts without CLI cron can call the secret URL shown in Settings → System:
 *     https://your-domain/cron.php?key=SECRET
 */
declare(strict_types=1);

define('NX_ROOT', __DIR__);
require NX_ROOT . '/app/bootstrap.php';

use App\Core\App;
use App\Core\ErrorHandler;
use App\Core\Settings;

ErrorHandler::register();
App::loadConfig();
if (!App::installed()) {
    http_response_code(503);
    exit("Not installed\n");
}

if (PHP_SAPI !== 'cli') {
    $key = (string) ($_GET['key'] ?? '');
    $expected = (string) Settings::get('cron.key', '');
    if ($expected === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: application/json');
}

\App\Core\Events::boot();
\App\Core\Extensions::boot();
$report = \App\Services\CronService::run();
echo json_encode(['ok' => true, 'at' => gmdate('c'), 'jobs' => $report], JSON_PRETTY_PRINT) . "\n";

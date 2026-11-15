<?php
/**
 * Developer tool: installs NextaCRM against SQLite (for automated tests) in the current copy.
 * Usage: php tools/smoke-install.php /path/to/test.sqlite [--demo]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('NX_ROOT', dirname(__DIR__));
require NX_ROOT . '/app/bootstrap.php';
App\Core\ErrorHandler::register();
$db = $argv[1] ?? (NX_ROOT . '/storage/test.sqlite');
@unlink($db);
@unlink(NX_ROOT . '/config/config.php');
@unlink(NX_ROOT . '/storage/installed.lock');
try {
App\Services\InstallService::install([
    'db' => ['driver' => 'sqlite', 'database' => $db],
    'url' => 'http://127.0.0.1:8099',
    'admin' => ['name' => 'Rupan Santra', 'email' => 'admin@example.com', 'password' => 'Str0ngPassw0rd!'],
    'company' => ['name' => 'Nexta Web Solution Pvt. Ltd.', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'country' => 'India', 'app_name' => 'NextaCRM'],
    'demo' => in_array('--demo', $argv, true),
]);
} catch (App\Core\HttpException $e) { fwrite(STDERR, $e->getMessage() . ' ' . json_encode($e->errors) . "\n" . $e->getTraceAsString() . "\n"); exit(1); }
echo "Installed. Tables: " . count(App\Core\App::db()->column("SELECT name FROM sqlite_master WHERE type='table'")) . "\n";
foreach (['leads', 'customers', 'deals', 'projects', 'tasks', 'tickets', 'invoices', 'payments', 'quotes', 'employees'] as $t) {
    echo str_pad($t, 12) . App\Core\App::db()->value("SELECT COUNT(*) FROM $t") . "\n";
}

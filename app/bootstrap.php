<?php
declare(strict_types=1);
defined('NX_ROOT') || exit;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

// PSR-4 autoloader for the application and vendored libraries.
spl_autoload_register(static function (string $class): void {
    static $map = null;
    if ($map === null) {
        $map = ['App\\' => NX_ROOT . '/app/'];
        $vendor = NX_ROOT . '/vendor/autoload_map.php';
        if (is_file($vendor)) {
            $map += require $vendor;
        }
    }
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

if (is_file(NX_ROOT . '/vendor/bootstrap.php')) {
    require NX_ROOT . '/vendor/bootstrap.php';
}
require NX_ROOT . '/app/Core/helpers.php';

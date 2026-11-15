<?php
/**
 * NextaCRM front controller.
 * All web and API requests (except static /assets) are routed through here.
 */
declare(strict_types=1);

define('NX_ROOT', __DIR__);
define('NX_START', microtime(true));

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('NextaCRM requires PHP 8.2 or newer. Current version: ' . PHP_VERSION);
}

require NX_ROOT . '/app/bootstrap.php';

App\Core\App::run();

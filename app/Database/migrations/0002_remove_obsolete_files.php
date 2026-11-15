<?php
declare(strict_types=1);
defined('NX_ROOT') || exit;

// v1.0.1: remove files that 1.0.0 shipped but 1.0.1 no longer does (development scripts in vendor/,
// replaced library files, the CLI test installer). Updates from 1.0.0 run the old updater, which
// cannot prune by itself; later versions prune automatically (UpdateService::removeStaleFiles()).
return static function (App\Core\DB $db): void {
    $obsolete = [
        'tools/smoke-install.php',
        'vendor/dompdf/CONTRIBUTING.md',
        'vendor/dompdf/bin/create-release.php',
        'vendor/dompdf/bin/update-reference-output.php',
        'vendor/html5-php/bin/entities.php',
        'vendor/html5-php/example.php',
        'vendor/php-css-parser/bin/quickdump.php',
        'vendor/phpmailer/.codecov.yml',
        'vendor/phpmailer/.phan/config.php',
        'vendor/phpmailer/get_oauth_token.php',
        'vendor/phpmailer/phpdoc.dist.xml',
        'vendor/phpseclib/BACKERS.md',
    ];
    foreach ($obsolete as $rel) {
        $path = NX_ROOT . '/' . $rel;
        if (is_file($path) && !str_contains($rel, '..')) {
            @unlink($path);
        }
    }
    foreach (['vendor/dompdf/bin', 'vendor/html5-php/bin', 'vendor/php-css-parser/bin', 'vendor/phpmailer/.phan'] as $dir) {
        @rmdir(NX_ROOT . '/' . $dir);
    }
};

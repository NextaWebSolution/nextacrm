<?php
defined('NX_ROOT') || exit;
// Dompdf ships Cpdf outside its PSR-4 tree (Composer "classmap").
spl_autoload_register(static function (string $class): void {
    if ($class === 'Dompdf\\Cpdf') {
        require NX_ROOT . '/vendor/dompdf/lib/Cpdf.php';
    }
}, true, true);

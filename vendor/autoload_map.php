<?php
// PSR-4 prefixes for vendored libraries (no Composer required on the server).
defined('NX_ROOT') || exit;
$v = NX_ROOT . '/vendor/';
return [
    'phpseclib3\\' => $v . 'phpseclib/phpseclib/',
    'ParagonIE\\ConstantTime\\' => $v . 'constant_time_encoding/src/',
    'PHPMailer\\PHPMailer\\' => $v . 'phpmailer/src/',
    'Dompdf\\' => $v . 'dompdf/src/',
    'FontLib\\' => $v . 'php-font-lib/src/FontLib/',
    'Svg\\' => $v . 'php-svg-lib/src/Svg/',
    'Masterminds\\' => $v . 'html5-php/src/',
    'Sabberworm\\CSS\\' => $v . 'php-css-parser/src/',
];

<?php
// Example only. The web installer writes the real config/config.php for you.
// Copy to config/config.php for a manual install, or set the NEXTACRM_* environment
// variables listed in docs/INSTALL.md, which override the values below.
defined('NX_ROOT') || exit;
return [
    'app_key' => 'REPLACE_WITH_OUTPUT_OF: php -r "echo base64_encode(random_bytes(32));"',
    'url' => 'https://crm.example.com',
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'example_crm',
        'username' => 'example_user',
        'password' => 'CHANGE_ME',
    ],
    'timezone' => 'Asia/Kolkata',
    'locale' => 'en',
    'debug' => false,          // never true in production
    'force_https' => true,
    'trusted_proxies' => [],   // IPs of your load balancer / reverse proxy, if any
];

# Vendored libraries

Installed from the official GitHub release tags (Composer is not required on the server).

| Library | Version | License | Used for |
|---|---|---|---|
| phpseclib/phpseclib | 3.0.58 | MIT | SFTP storage driver |
| paragonie/constant_time_encoding | 3.0.0 | MIT | phpseclib dependency |
| PHPMailer/PHPMailer | 6.12.0 | LGPL-2.1 | SMTP e-mail |
| dompdf/dompdf | 3.1.6 | LGPL-2.1 | Invoice / quotation PDFs |
| dompdf/php-font-lib | 1.0.2 | LGPL-2.1 | Dompdf dependency |
| dompdf/php-svg-lib | 1.0.2 | LGPL-3.0 | Dompdf dependency |
| masterminds/html5 | 2.9.0 | MIT | Dompdf dependency |
| sabberworm/php-css-parser | 8.8.0 | MIT | Dompdf dependency |

Front-end (built from source into assets/js): Alpine.js 3.17.5 (MIT), Chart.js 4.4.7 (MIT), icons from Lucide (ISC). CSS compiled with Tailwind CSS 4.3 (MIT).

To update a library: download the new release tag, replace its folder, keep the paths in `autoload_map.php` in sync, then run `php tools/smoke-install.php /tmp/t.sqlite --demo` and generate a PDF. Development-only scripts (bin/, examples, get_oauth_token.php) are deliberately removed.

<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\InstallService;

/**
 * Web installer (runs only while storage/installed.lock is absent).
 * Steps: welcome → requirements → extensions → database → connection test → URL → administrator
 *        → company → migration → modules → done.
 */
final class InstallController
{
    public function handle(Request $r): Response
    {
        if (App::installed()) {
            throw new HttpException(404);
        }
        // A config without the lock means a previous install stopped half-way. Require manual cleanup
        // so that nobody can re-run the installer against an existing configuration.
        if (is_file(NX_ROOT . '/config/config.php')) {
            return Response::html(\App\Core\ErrorHandler::plainErrorPage(409, 'config/config.php already exists but the installation lock is missing. Delete config/config.php (and empty the database if needed) to run the installer again.'), 409);
        }
        Session::start();
        if ($r->path === '/install/check' || ($r->method === 'POST')) {
            return $this->action($r);
        }
        return Response::html(View::partial('install/wizard', [
            'requirements' => InstallService::requirements(),
            'defaultUrl' => ($r->isSecure() ? 'https' : 'http') . '://' . $r->host() . $r->basePath,
            'manifest' => App::manifest(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]));
    }

    private function action(Request $r): Response
    {
        Csrf::verify($r);
        $action = $r->str('action');
        $in = $r->json() ?: $r->all();
        try {
            return match ($action) {
                'requirements' => Response::json(['checks' => InstallService::requirements()]),
                'test_db' => $this->testDb($in),
                'install' => $this->install($in),
                default => Response::json(['ok' => false, 'message' => 'Unknown action'], 400),
            };
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::report($e);
            return Response::json(['ok' => false, 'message' => 'Installation failed: ' . $e->getMessage()], 500);
        }
    }

    private function dbConfig(array $in): array
    {
        $db = (array) ($in['db'] ?? []);
        return [
            'driver' => 'mysql',
            'host' => trim((string) ($db['host'] ?? 'localhost')) ?: 'localhost',
            'port' => (int) ($db['port'] ?? 3306) ?: 3306,
            'database' => trim((string) ($db['database'] ?? '')),
            'username' => trim((string) ($db['username'] ?? '')),
            'password' => (string) ($db['password'] ?? ''),
        ];
    }

    private function testDb(array $in): Response
    {
        $cfg = $this->dbConfig($in);
        if ($cfg['database'] === '' || $cfg['username'] === '') {
            return Response::json(['ok' => false, 'message' => 'Database name and username are required.']);
        }
        [$ok, $msg] = InstallService::testDatabase($cfg);
        return Response::json(['ok' => $ok, 'message' => $msg]);
    }

    private function install(array $in): Response
    {
        foreach (InstallService::requirements() as $c) {
            if ($c['required'] && !$c['ok']) {
                return Response::json(['ok' => false, 'message' => 'Requirement not met: ' . $c['label']]);
            }
        }
        $cfg = $this->dbConfig($in);
        [$ok, $msg] = InstallService::testDatabase($cfg);
        if (!$ok) {
            return Response::json(['ok' => false, 'message' => $msg, 'step' => 'database']);
        }
        $url = rtrim(trim((string) ($in['url'] ?? '')), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $url)) {
            return Response::json(['ok' => false, 'message' => 'Enter a valid application URL starting with http:// or https://', 'step' => 'url']);
        }
        $admin = (array) ($in['admin'] ?? []);
        $email = mb_strtolower(trim((string) ($admin['email'] ?? '')));
        $name = trim((string) ($admin['name'] ?? ''));
        $pw = (string) ($admin['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['ok' => false, 'message' => 'Enter the administrator name and a valid e-mail.', 'step' => 'admin']);
        }
        if ($pw !== (string) ($admin['password_confirmation'] ?? '')) {
            return Response::json(['ok' => false, 'message' => 'The administrator passwords do not match.', 'step' => 'admin']);
        }
        if ($err = Auth::validatePassword($pw, $email)) {
            return Response::json(['ok' => false, 'message' => $err, 'step' => 'admin']);
        }
        $company = (array) ($in['company'] ?? []);
        if (trim((string) ($company['name'] ?? '')) === '') {
            return Response::json(['ok' => false, 'message' => 'Enter your company name.', 'step' => 'company']);
        }
        $tz = (string) ($company['timezone'] ?? 'UTC');
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($company['currency'] ?? 'INR')) ?: 'INR');
        @set_time_limit(300);
        InstallService::install([
            'db' => $cfg,
            'url' => $url,
            'admin' => ['name' => mb_substr($name, 0, 150), 'email' => $email, 'password' => $pw],
            'company' => ['name' => mb_substr(trim((string) $company['name']), 0, 190), 'currency' => substr($currency, 0, 3), 'timezone' => $tz, 'country' => mb_substr((string) ($company['country'] ?? ''), 0, 100), 'tax_id' => mb_substr((string) ($company['tax_id'] ?? ''), 0, 50) ?: null, 'phone' => mb_substr((string) ($company['phone'] ?? ''), 0, 50) ?: null, 'app_name' => mb_substr(trim((string) ($company['app_name'] ?? '')) ?: 'NextaCRM', 0, 60)],
            'demo' => !empty($in['demo']),
        ]);
        $disabled = array_values(array_filter((array) ($in['modules_disabled'] ?? []), 'is_string'));
        if ($disabled) {
            \App\Core\Settings::set('app.modules_disabled', $disabled);
        }
        Session::destroy();
        return Response::json(['ok' => true, 'message' => 'Installation complete.', 'redirect' => $url . '/login']);
    }
}

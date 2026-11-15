<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Application kernel and tiny service container.
 */
final class App
{
    private static array $services = [];
    private static array $config = [];
    private static ?Request $request = null;
    public static string $requestId = '';

    public static function run(): void
    {
        self::$requestId = bin2hex(random_bytes(6));
        ErrorHandler::register();
        self::loadConfig();

        $request = Request::capture();
        self::$request = $request;

        try {
            if (!self::installed()) {
                (new \App\Http\Controllers\InstallController())->handle($request)->send();
                return;
            }
            // Force HTTPS (enabled by the installer only when the origin itself served HTTPS, so proxies with
            // "flexible" TLS cannot cause a redirect loop; set 'trusted_proxies' in config for load balancers).
            if (self::config('force_https') && !$request->isSecure() && str_starts_with((string) self::config('url', ''), 'https://') && in_array($request->method, ['GET', 'HEAD'], true)) {
                $qs = (string) ($request->server['QUERY_STRING'] ?? '');
                $target = rtrim((string) self::config('url'), '/') . $request->path . ($qs !== '' ? '?' . $qs : '');
                (new Response('', 301, ['Location' => $target]))->send();
                return;
            }
            if (self::isMaintenance() && !str_starts_with($request->path, '/login')) {
                Session::start();
                $u = Auth::user();
                if (!$u || !$u['is_admin']) {
                    Response::view('errors/maintenance', [], 503, null)->send();
                    return;
                }
            }
            Events::boot();
            Extensions::boot();
            $router = new Router();
            require NX_ROOT . '/app/routes.php';
            Extensions::registerRoutes($router);
            $response = $router->dispatch($request);
            $response->send();
        } catch (HttpException $e) {
            ErrorHandler::renderHttp($e, $request)->send();
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
            ErrorHandler::renderFatal($request)->send();
        }
    }

    public static function loadConfig(): void
    {
        $file = NX_ROOT . '/config/config.php';
        self::$config = is_file($file) ? (array) require $file : [];
        // Optional environment overrides (VPS/Docker). On cPanel the installer-written config file is used.
        $env = static fn (string $k): ?string => ($v = getenv($k)) !== false && $v !== '' ? $v : null;
        foreach (['NEXTACRM_APP_KEY' => ['app_key'], 'NEXTACRM_URL' => ['url'], 'NEXTACRM_DB_HOST' => ['db', 'host'], 'NEXTACRM_DB_PORT' => ['db', 'port'],
                  'NEXTACRM_DB_NAME' => ['db', 'database'], 'NEXTACRM_DB_USER' => ['db', 'username'], 'NEXTACRM_DB_PASSWORD' => ['db', 'password']] as $var => $path) {
            if (($v = $env($var)) !== null) {
                count($path) === 1 ? self::$config[$path[0]] = $v : self::$config[$path[0]][$path[1]] = $v;
            }
        }
        if (($v = $env('NEXTACRM_TRUSTED_PROXIES')) !== null) {
            self::$config['trusted_proxies'] = array_values(array_filter(array_map('trim', explode(',', $v))));
        }
        // Fail closed on a missing or weak encryption key once installed (never run with a guessable key).
        if (is_file(NX_ROOT . '/storage/installed.lock')) {
            $key = base64_decode((string) (self::$config['app_key'] ?? ''), true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                http_response_code(500);
                error_log('NextaCRM: app_key is missing or invalid in config/config.php (or NEXTACRM_APP_KEY).');
                exit('Configuration error. The administrator has been notified in the server error log.');
            }
        }
        if (!empty(self::$config['timezone_db'])) {
            date_default_timezone_set('UTC');
        }
        if (!empty(self::$config['debug'])) {
            ini_set('display_errors', '0'); // never display; debug only adds detail to logs
        }
    }

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function installed(): bool
    {
        return is_file(NX_ROOT . '/storage/installed.lock') && is_file(NX_ROOT . '/config/config.php');
    }

    public static function isMaintenance(): bool
    {
        return is_file(NX_ROOT . '/storage/maintenance.flag');
    }

    public static function request(): Request
    {
        return self::$request ??= Request::capture();
    }

    public static function setRequest(Request $r): void
    {
        self::$request = $r;
    }

    public static function db(): DB
    {
        return self::$services['db'] ??= new DB((array) self::config('db', []));
    }

    public static function setDb(DB $db): void
    {
        self::$services['db'] = $db;
    }

    /** Lazily resolved singleton services. */
    public static function make(string $class): object
    {
        return self::$services[$class] ??= new $class();
    }

    public static function reset(): void
    {
        self::$services = [];
    }

    public static function manifest(): array
    {
        static $m = null;
        return $m ??= json_decode((string) file_get_contents(NX_ROOT . '/manifest.json'), true) ?: [];
    }

    public static function version(): string
    {
        return (string) (self::manifest()['version'] ?? '1.0.0');
    }
}

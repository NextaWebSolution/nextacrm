<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        if (PHP_SAPI === 'cli' && !defined('NX_TESTING')) {
            $_SESSION = $_SESSION ?? [];
            self::$started = true;
            return;
        }
        $dir = NX_ROOT . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        $req = App::request();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        // Absolute/idle timeouts are enforced in Auth via user_sessions; GC just needs to outlive them.
        ini_set('session.gc_maxlifetime', (string) max(3600, (int) App::config('session_gc_minutes', 1440) * 60));
        session_name('nxsid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => ($req->basePath ?: '') . '/',
            'secure' => $req->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (defined('NX_TESTING')) {
            $_SESSION = $_SESSION ?? [];
        } else {
            session_start();
        }
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = self::get($key, $default);
        self::forget($key);
        return $v;
    }

    public static function regenerate(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }

    public static function flash(string $type, string $message): void
    {
        $f = self::get('_flash', []);
        $f[] = ['type' => $type, 'message' => $message];
        self::put('_flash', $f);
    }

    public static function takeFlash(): array
    {
        return (array) self::pull('_flash', []);
    }
}

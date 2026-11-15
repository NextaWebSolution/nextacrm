<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class Middleware
{
    public static function run(array $list, Request $r): void
    {
        foreach ($list as $mw) {
            [$name, $arg] = array_pad(explode(':', $mw, 2), 2, null);
            match ($name) {
                'auth' => self::auth($r),
                'guest' => self::guest(),
                'csrf' => Csrf::verify($r),
                'admin' => self::admin(),
                'api' => \App\Http\Controllers\Api\ApiAuth::handle($r),
                'perm' => self::perm((string) $arg),
                'throttle' => self::throttle($r, (string) $arg),
                default => throw new \LogicException("Unknown middleware $name"),
            };
        }
    }

    private static function auth(Request $r): void
    {
        if (!Auth::check()) {
            if ($r->wantsJson()) {
                throw new HttpException(401);
            }
            $next = $r->path . ($r->query ? '?' . http_build_query($r->query) : '');
            Session::put('intended', $next);
            throw new RedirectException('/login');
        }
        if (Auth::companyId() === 0) {
            throw new HttpException(403, 'Your account is not linked to any company. Ask an administrator to grant access.');
        }
        $u = Auth::user();
        // Enforce 2FA enrolment for admins when the policy is on.
        if (setting('security.enforce_2fa_admins', false) && $u['is_admin'] && empty($u['two_factor_enabled'])
            && !str_starts_with($r->path, '/profile') && $r->path !== '/logout') {
            Session::flash('warning', 'Two-factor authentication is required for administrators. Please enable it to continue.');
            throw new RedirectException('/profile/security');
        }
    }

    private static function guest(): void
    {
        if (Auth::check()) {
            throw new RedirectException('/');
        }
    }

    private static function admin(): void
    {
        if (empty(Auth::user()['is_admin'])) {
            throw new HttpException(403);
        }
    }

    private static function perm(string $arg): void
    {
        [$module, $action] = array_pad(explode('.', $arg, 2), 2, 'view');
        Permissions::authorize($module, $action);
    }

    private static function throttle(Request $r, string $arg): void
    {
        [$bucket, $max, $window] = array_pad(explode(',', $arg), 3, null);
        // Signed-in actions are limited per user (offices share one IP); everything else per IP.
        $who = in_array($bucket, ['upload', 'export', 'import', 'reauth'], true) && Auth::check() ? 'u' . Auth::id() : $r->ip();
        $key = 'throttle:' . $bucket . ':' . $who;
        if (!RateLimiter::hit($key, (int) ($max ?: 60), (int) ($window ?: 60))) {
            throw new HttpException(429);
        }
    }
}

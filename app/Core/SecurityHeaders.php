<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class SecurityHeaders
{
    /** Allow specific responses (e.g. embeddable web-to-lead form) to relax framing. */
    public static bool $allowFraming = false;

    public static function forResponse(Response $r): array
    {
        $h = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Request-Id' => App::$requestId,
        ];
        $frame = self::$allowFraming ? '*' : "'none'";
        if (!self::$allowFraming) {
            $h['X-Frame-Options'] = 'DENY';
        }
        // Alpine.js evaluates directive expressions with Function(), which requires 'unsafe-eval'.
        // No inline <script> blocks are used anywhere, so 'unsafe-inline' is NOT allowed for scripts.
        $h['Content-Security-Policy'] = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors $frame",
        ]);
        if (App::request()->isSecure()) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return $h;
    }
}

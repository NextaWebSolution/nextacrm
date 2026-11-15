<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;

/** Bearer-token authentication for /api/v1. Tokens are stored as SHA-256 hashes. */
final class ApiAuth
{
    public static function handle(Request $r): void
    {
        $header = (string) $r->header('Authorization');
        // Slow down token guessing / credential stuffing against the API (failures only are counted below).
        if (!RateLimiter::check('apifail:' . $r->ip(), 30)) {
            throw new HttpException(429, 'Too many failed authentication attempts. Try again later.');
        }
        if (!preg_match('/^Bearer\s+(nx_[A-Za-z0-9_\-]{20,100})$/', trim($header), $m)) {
            RateLimiter::hit('apifail:' . $r->ip(), 30, 600);
            throw new HttpException(401, 'Missing or malformed bearer token.');
        }
        $db = App::db();
        $token = $db->one('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $m[1])]);
        if (!$token || ($token['expires_at'] && strtotime($token['expires_at'] . ' UTC') < time())) {
            RateLimiter::hit('apifail:' . $r->ip(), 30, 600);
            throw new HttpException(401, 'Invalid or expired token.');
        }
        if (!RateLimiter::hit('api:' . $token['id'], 120, 60)) {
            throw new HttpException(429, 'Rate limit exceeded (120 requests per minute).');
        }
        $user = $db->one("SELECT u.*, r.name AS role_name, r.is_admin AS role_is_admin, r.permissions AS role_permissions FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = 'active' AND u.deleted_at IS NULL", [$token['user_id']]);
        if (!$user || !$db->value('SELECT id FROM company_user WHERE user_id = ? AND company_id = ?', [$user['id'], $token['company_id']])) {
            throw new HttpException(401, 'The token owner no longer has access.');
        }
        Auth::setApiUser($user, $token);
        if (!$token['last_used_at'] || strtotime($token['last_used_at'] . ' UTC') < time() - 60) {
            $db->update('api_tokens', ['last_used_at' => DB::now(), 'last_used_ip' => $r->ip()], ['id' => $token['id']]);
        }
    }
}

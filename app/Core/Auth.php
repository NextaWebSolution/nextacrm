<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Session authentication with brute-force protection (per IP, per username, per 2FA code),
 * Argon2id hashing, TOTP 2FA, DB-tracked sessions (revocable) and idle/absolute timeouts.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;
    private static ?array $apiToken = null;

    public const MAX_PER_USER = 5;      // failed attempts per account per window
    public const MAX_PER_IP = 20;       // failed attempts per IP per window
    public const WINDOW = 900;          // 15 minutes

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        Session::start();
        $uid = (int) Session::get('uid', 0);
        $sid = (string) Session::get('sid', '');
        if ($uid <= 0 || $sid === '') {
            return null;
        }
        $db = App::db();
        $row = $db->one('SELECT * FROM user_sessions WHERE token_hash = ? AND user_id = ? AND revoked_at IS NULL', [hash('sha256', $sid), $uid]);
        $idle = max(5, (int) setting('security.idle_timeout', 120)) * 60;
        $absolute = max(60, (int) setting('security.session_lifetime', 720)) * 60;
        if (!$row || strtotime($row['last_seen_at'] . ' UTC') < time() - $idle || strtotime($row['created_at'] . ' UTC') < time() - $absolute) {
            self::clearSession();
            return null;
        }
        // Basic binding to the browser: a stolen cookie used from another UA is rejected.
        if (!hash_equals((string) $row['ua_hash'], hash('sha256', App::request()->userAgent()))) {
            self::clearSession();
            return null;
        }
        $user = $db->one('SELECT u.*, r.name AS role_name, r.is_admin AS role_is_admin, r.permissions AS role_permissions FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = ? AND u.deleted_at IS NULL', [$uid, 'active']);
        if (!$user) {
            self::clearSession();
            return null;
        }
        if (strtotime($row['last_seen_at'] . ' UTC') < time() - 60) {
            $db->update('user_sessions', ['last_seen_at' => DB::now(), 'ip' => App::request()->ip()], ['id' => $row['id']]);
        }
        self::$user = self::hydrate($user);
        self::$user['session_id'] = (int) $row['id'];
        return self::$user;
    }

    private static function hydrate(array $user): array
    {
        $user['is_admin'] = (bool) ($user['role_is_admin'] ?? false);
        $user['permissions'] = json_decode((string) ($user['role_permissions'] ?? '{}'), true) ?: [];
        $user['prefs'] = json_decode((string) ($user['preferences'] ?? '{}'), true) ?: [];
        $user['team_ids'] = array_map('intval', App::db()->column('SELECT team_id FROM team_user WHERE user_id = ?', [$user['id']]));
        $user['company_ids'] = array_map('intval', App::db()->column('SELECT company_id FROM company_user WHERE user_id = ?', [$user['id']]));
        unset($user['password'], $user['two_factor_secret'], $user['recovery_codes']);
        return $user;
    }

    /** Used by the API layer once a token is validated. */
    public static function setApiUser(array $user, array $token): void
    {
        self::$user = self::hydrate($user);
        self::$resolved = true;
        self::$apiToken = $token;
    }

    public static function apiToken(): ?array
    {
        return self::$apiToken;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
        self::$apiToken = null;
    }

    /**
     * First factor. Returns ['status' => 'ok'|'2fa'|'locked'|'invalid'|'unverified', 'retry' => seconds].
     */
    public static function attempt(string $email, string $password): array
    {
        $req = App::request();
        $ip = $req->ip();
        $email = mb_strtolower(trim($email));
        $db = App::db();
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW);

        $ipFails = (int) $db->value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [$ip, $since]);
        $userFails = (int) $db->value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?', [$email, $since]);
        if ($ipFails >= self::MAX_PER_IP || $userFails >= self::MAX_PER_USER) {
            Audit::log('auth.locked', null, null, ['email' => $email]);
            return ['status' => 'locked', 'retry' => self::WINDOW];
        }

        $user = $db->one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
        // Always run a hash verification to keep timing similar for unknown accounts.
        $hash = $user['password'] ?? '$argon2id$v=19$m=65536,t=3,p=1$V05TVEdPTnlPejdtcExvQg$Uuj+7/gJ4xQw84f3iPOk7pBqWm9173wTu/Zxlh4307g';
        $ok = password_verify($password, (string) $hash) && $user !== null;

        if (!$ok || $user['status'] !== 'active') {
            $db->insert('login_attempts', ['email' => $email, 'ip' => $ip, 'success' => 0, 'created_at' => DB::now()]);
            Audit::log('auth.failed', null, null, ['email' => $email], $user ? (int) $user['id'] : null);
            return ['status' => 'invalid'];
        }
        if (Crypto::needsRehash((string) $user['password'])) {
            $db->update('users', ['password' => Crypto::hashPassword($password)], ['id' => $user['id']]);
        }
        $db->insert('login_attempts', ['email' => $email, 'ip' => $ip, 'success' => 1, 'created_at' => DB::now()]);

        if (!empty($user['two_factor_enabled'])) {
            Session::regenerate();
            Session::put('2fa_uid', (int) $user['id']);
            Session::put('2fa_at', time());
            return ['status' => '2fa'];
        }
        self::login($user);
        return ['status' => 'ok'];
    }

    /** Second factor: TOTP or a recovery code. */
    public static function attemptTwoFactor(string $code): array
    {
        $uid = (int) Session::get('2fa_uid', 0);
        if ($uid <= 0 || time() - (int) Session::get('2fa_at', 0) > 600) {
            Session::forget('2fa_uid');
            return ['status' => 'expired'];
        }
        $db = App::db();
        $ip = App::request()->ip();
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW);
        $fails = (int) $db->value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?', ['2fa:' . $uid, $since]);
        if ($fails >= self::MAX_PER_USER) {
            return ['status' => 'locked', 'retry' => self::WINDOW];
        }
        $user = $db->find('users', $uid);
        if (!$user || $user['status'] !== 'active') {
            return ['status' => 'expired'];
        }
        $secret = Crypto::decrypt($user['two_factor_secret']);
        $code = trim($code);
        $valid = false;
        if (preg_match('/^\d{6}$/', preg_replace('/\s/', '', $code) ?? '')) {
            $step = Totp::verify($secret, $code, $user['two_factor_last_step'] !== null ? (int) $user['two_factor_last_step'] : null);
            if ($step !== null) {
                $db->update('users', ['two_factor_last_step' => $step], ['id' => $uid]);
                $valid = true;
            }
        } else {
            $valid = self::useRecoveryCode($user, $code);
        }
        if (!$valid) {
            $db->insert('login_attempts', ['email' => '2fa:' . $uid, 'ip' => $ip, 'success' => 0, 'created_at' => DB::now()]);
            return ['status' => 'invalid'];
        }
        Session::forget('2fa_uid');
        self::login($user);
        return ['status' => 'ok'];
    }

    private static function useRecoveryCode(array $user, string $code): bool
    {
        $codes = json_decode((string) $user['recovery_codes'], true) ?: [];
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        foreach ($codes as $i => $hash) {
            if (password_verify($normalized, $hash)) {
                unset($codes[$i]);
                App::db()->update('users', ['recovery_codes' => json_encode(array_values($codes))], ['id' => $user['id']]);
                Audit::log('auth.recovery_code_used', 'users', (int) $user['id']);
                return true;
            }
        }
        return false;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        $sid = Crypto::token(32);
        $req = App::request();
        $db = App::db();
        $db->insert('user_sessions', [
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $sid),
            'ip' => $req->ip(),
            'user_agent' => $req->userAgent(),
            'ua_hash' => hash('sha256', $req->userAgent()),
            'created_at' => DB::now(),
            'last_seen_at' => DB::now(),
        ]);
        Session::put('uid', (int) $user['id']);
        Session::put('sid', $sid);
        $companyIds = array_map('intval', $db->column('SELECT company_id FROM company_user WHERE user_id = ?', [$user['id']]));
        $preferred = (int) ($user['default_company_id'] ?? 0);
        Session::put('company_id', in_array($preferred, $companyIds, true) ? $preferred : ($companyIds[0] ?? 0));
        $db->update('users', ['last_login_at' => DB::now(), 'last_login_ip' => $req->ip()], ['id' => $user['id']]);
        self::reset();
        Audit::log('auth.login', 'users', (int) $user['id'], null, (int) $user['id']);
    }

    public static function logout(): void
    {
        $sid = (string) Session::get('sid', '');
        if ($sid !== '') {
            App::db()->query('UPDATE user_sessions SET revoked_at = ? WHERE token_hash = ?', [DB::now(), hash('sha256', $sid)]);
        }
        Audit::log('auth.logout', 'users', self::id());
        Session::destroy();
        self::reset();
    }

    /** Revoke every session of a user, optionally keeping the current one. */
    public static function logoutEverywhere(int $userId, bool $keepCurrent = false): int
    {
        $params = [DB::now(), $userId];
        $sql = 'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL';
        if ($keepCurrent && ($sid = (string) Session::get('sid', '')) !== '') {
            $sql .= ' AND token_hash <> ?';
            $params[] = hash('sha256', $sid);
        }
        return App::db()->query($sql, $params)->rowCount();
    }

    private static function clearSession(): void
    {
        Session::forget('uid');
        Session::forget('sid');
    }

    private static ?int $forcedCompany = null;

    /**
     * Run code as a given user in a given company (cron jobs, scheduled workflows, public forms).
     */
    public static function actingAs(int $userId, int $companyId, callable $fn): mixed
    {
        $prev = [self::$user, self::$resolved, self::$forcedCompany, self::$apiToken];
        $row = App::db()->one('SELECT u.*, r.name AS role_name, r.is_admin AS role_is_admin, r.permissions AS role_permissions FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$userId]);
        if (!$row) {
            $row = App::db()->one("SELECT u.*, r.name AS role_name, r.is_admin AS role_is_admin, r.permissions AS role_permissions FROM users u JOIN roles r ON r.id = u.role_id JOIN company_user cu ON cu.user_id = u.id WHERE r.is_admin = 1 AND cu.company_id = ? AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1", [$companyId]);
        }
        if (!$row) {
            return null;
        }
        self::$user = self::hydrate($row);
        self::$resolved = true;
        self::$forcedCompany = $companyId;
        self::$apiToken = null;
        Permissions::reset();
        Settings::flush();
        try {
            return $fn();
        } finally {
            [self::$user, self::$resolved, self::$forcedCompany, self::$apiToken] = $prev;
            Permissions::reset();
            Settings::flush();
        }
    }

    public static function companyId(): int
    {
        if (self::$forcedCompany !== null) {
            return self::$forcedCompany;
        }
        $u = self::user();
        if (!$u) {
            return 0;
        }
        $cid = (int) Session::get('company_id', 0);
        if (self::$apiToken) {
            $cid = (int) (self::$apiToken['company_id'] ?? 0);
        }
        if (!in_array($cid, $u['company_ids'], true)) {
            $cid = $u['company_ids'][0] ?? 0;
            if (!self::$apiToken) {
                Session::put('company_id', $cid);
            }
        }
        return $cid;
    }

    public static function validatePassword(string $password, string $email = ''): ?string
    {
        $min = max(8, (int) setting('security.password_min', 10));
        if (mb_strlen($password) < $min) {
            return "Password must be at least $min characters.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain letters and numbers.';
        }
        $lower = mb_strtolower($password);
        foreach (['password', '12345678', 'qwerty', 'letmein', 'admin123', 'welcome1'] as $weak) {
            if (str_contains($lower, $weak)) {
                return 'This password is too common.';
            }
        }
        if ($email !== '' && str_contains($lower, mb_strtolower(explode('@', $email)[0]))) {
            return 'Password must not contain your e-mail name.';
        }
        return null;
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Mailer;
use App\Core\Session;

/**
 * Customer portal accounts live on contacts (portal_enabled + Argon2id portal_password). Portal sessions are
 * separate from staff sessions (own table, own session key) and every query is scoped to the contact's customer.
 */
final class PortalService
{
    private const IDLE = 3600;          // 1 hour
    private const ABSOLUTE = 43200;     // 12 hours
    private const MAX_FAILS = 8;
    private static ?array $current = null;
    private static bool $resolved = false;

    public static function current(): ?array
    {
        if (self::$resolved) {
            return self::$current;
        }
        self::$resolved = true;
        Session::start();
        $p = Session::get('portal');
        if (!is_array($p) || empty($p['cid']) || empty($p['sid'])) {
            return null;
        }
        $db = App::db();
        $s = $db->one('SELECT * FROM portal_sessions WHERE token_hash = ? AND contact_id = ? AND revoked_at IS NULL', [hash('sha256', (string) $p['sid']), (int) $p['cid']]);
        if (!$s || strtotime($s['last_seen_at'] . ' UTC') < time() - self::IDLE || strtotime($s['created_at'] . ' UTC') < time() - self::ABSOLUTE
            || !hash_equals((string) $s['ua_hash'], hash('sha256', App::request()->userAgent()))) {
            Session::forget('portal');
            return null;
        }
        $c = $db->one('SELECT c.*, cu.name AS customer_name FROM contacts c JOIN customers cu ON cu.id = c.customer_id AND cu.deleted_at IS NULL WHERE c.id = ? AND c.deleted_at IS NULL AND c.portal_enabled = 1', [(int) $p['cid']]);
        if (!$c || !$c['customer_id']) {
            Session::forget('portal');
            return null;
        }
        if (strtotime($s['last_seen_at'] . ' UTC') < time() - 60) {
            $db->update('portal_sessions', ['last_seen_at' => DB::now()], ['id' => $s['id']]);
        }
        $c['company'] = $db->find('companies', (int) $c['company_id']);
        return self::$current = $c;
    }

    public static function require(): array
    {
        $c = self::current();
        if (!$c) {
            throw new \App\Core\RedirectException('/portal/login');
        }
        return $c;
    }

    /** @return array{ok:bool, message?:string} */
    public static function login(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip = App::request()->ip();
        $db = App::db();
        $since = gmdate('Y-m-d H:i:s', time() - 900);
        $fails = (int) $db->value("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > ? AND (email = ? OR (ip = ? AND email LIKE 'portal:%'))", [$since, 'portal:' . $email, $ip]);
        if ($fails >= self::MAX_FAILS) {
            return ['ok' => false, 'message' => 'Too many attempts. Please wait 15 minutes.'];
        }
        $rows = $db->all('SELECT * FROM contacts WHERE LOWER(email) = ? AND portal_enabled = 1 AND deleted_at IS NULL AND portal_password IS NOT NULL', [$email]);
        $match = null;
        foreach ($rows as $row) {
            if (password_verify($password, (string) $row['portal_password'])) {
                $match = $row;
                break;
            }
        }
        if (!$rows) {
            password_verify($password, '$argon2id$v=19$m=65536,t=3,p=1$V05TVEdPTnlPejdtcExvQg$Uuj+7/gJ4xQw84f3iPOk7pBqWm9173wTu/Zxlh4307g');
        }
        $db->insert('login_attempts', ['email' => 'portal:' . $email, 'ip' => $ip, 'success' => $match ? 1 : 0, 'created_at' => DB::now()]);
        if (!$match) {
            return ['ok' => false, 'message' => 'The e-mail or password is incorrect.'];
        }
        self::startSession($match);
        return ['ok' => true];
    }

    private static function startSession(array $contact): void
    {
        Session::regenerate();
        $sid = Crypto::token(32);
        $req = App::request();
        App::db()->insert('portal_sessions', ['contact_id' => (int) $contact['id'], 'company_id' => (int) $contact['company_id'], 'token_hash' => hash('sha256', $sid), 'ua_hash' => hash('sha256', $req->userAgent()), 'ip' => $req->ip(), 'created_at' => DB::now(), 'last_seen_at' => DB::now()]);
        App::db()->update('contacts', ['portal_last_login' => DB::now()], ['id' => (int) $contact['id']]);
        Session::put('portal', ['cid' => (int) $contact['id'], 'sid' => $sid]);
        self::$resolved = false;
    }

    public static function logout(): void
    {
        $p = Session::get('portal');
        if (is_array($p) && !empty($p['sid'])) {
            App::db()->query('UPDATE portal_sessions SET revoked_at = ? WHERE token_hash = ?', [DB::now(), hash('sha256', (string) $p['sid'])]);
        }
        Session::forget('portal');
        Session::regenerate();
    }

    /** Staff action: enable portal access and e-mail an activation link. */
    public static function invite(int $contactId): void
    {
        $c = RecordService::for('contacts')->find($contactId, 'edit');
        if (empty($c['email'])) {
            throw new HttpException(422, 'Add an e-mail address to this contact first.');
        }
        if (empty($c['customer_id'])) {
            throw new HttpException(422, 'Link this contact to a customer first. The portal shows that customer\'s records.');
        }
        App::db()->update('contacts', ['portal_enabled' => 1], ['id' => $contactId]);
        $company = CompanyService::current();
        $link = self::issueToken($contactId, 'invite', 72);
        $body = '<p>Hi ' . e($c['first_name'] ?: $c['name']) . ',</p><p>' . e($company['name']) . ' has given you access to its client portal, where you can view invoices and quotations, pay online, follow your projects and raise support tickets.</p>'
            . '<p><a href="' . e($link) . '" style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Activate my account</a></p><p style="font-size:12px;color:#6b7280">This link is valid for 72 hours.</p>';
        Mailer::queue((string) $c['email'], 'Your ' . $company['name'] . ' client portal', $body);
        ActivityService::log('contacts', $contactId, 'system', 'Client portal invitation sent to ' . $c['email']);
        Audit::log('portal.invited', 'contacts', $contactId);
    }

    public static function disable(int $contactId): void
    {
        RecordService::for('contacts')->find($contactId, 'edit');
        App::db()->update('contacts', ['portal_enabled' => 0], ['id' => $contactId]);
        App::db()->query('UPDATE portal_sessions SET revoked_at = ? WHERE contact_id = ? AND revoked_at IS NULL', [DB::now(), $contactId]);
        Audit::log('portal.disabled', 'contacts', $contactId);
    }

    public static function issueToken(int $contactId, string $purpose, int $hours): string
    {
        $token = Crypto::token(32);
        $db = App::db();
        $db->query('UPDATE portal_tokens SET used_at = ? WHERE contact_id = ? AND used_at IS NULL', [DB::now(), $contactId]);
        $db->insert('portal_tokens', ['contact_id' => $contactId, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $hours * 3600), 'created_at' => DB::now()]);
        return full_url('/portal/activate/' . $token);
    }

    public static function findToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $token)) {
            return null;
        }
        $row = App::db()->one('SELECT t.*, c.email, c.name FROM portal_tokens t JOIN contacts c ON c.id = t.contact_id WHERE t.token_hash = ? AND t.used_at IS NULL AND c.deleted_at IS NULL', [hash('sha256', $token)]);
        return $row && strtotime($row['expires_at'] . ' UTC') > time() ? $row : null;
    }

    public static function setPassword(array $tokenRow, string $password): ?string
    {
        if ($err = Auth::validatePassword($password, (string) $tokenRow['email'])) {
            return $err;
        }
        $db = App::db();
        $db->update('contacts', ['portal_password' => Crypto::hashPassword($password), 'portal_enabled' => 1], ['id' => (int) $tokenRow['contact_id']]);
        $db->update('portal_tokens', ['used_at' => DB::now()], ['id' => (int) $tokenRow['id']]);
        $db->query('UPDATE portal_sessions SET revoked_at = ? WHERE contact_id = ? AND revoked_at IS NULL', [DB::now(), (int) $tokenRow['contact_id']]);
        return null;
    }

    /** Password reset request: same response whether or not the address exists. */
    public static function forgot(string $email): void
    {
        $email = mb_strtolower(trim($email));
        foreach (App::db()->all('SELECT id, company_id, name FROM contacts WHERE LOWER(email) = ? AND portal_enabled = 1 AND deleted_at IS NULL LIMIT 3', [$email]) as $c) {
            $link = self::issueToken((int) $c['id'], 'reset', 1);
            Mailer::queue($email, 'Reset your client portal password', '<p>Hi ' . e($c['name']) . ',</p><p>Use the link below to choose a new password. It is valid for 1 hour.</p><p><a href="' . e($link) . '">Choose a new password</a></p><p style="font-size:12px;color:#6b7280">If you did not ask for this, you can ignore this e-mail.</p>');
        }
    }

    // ── Scoped data access (every query bound to the contact's customer and company) ──
    public static function invoices(array $c): array
    {
        return App::db()->all("SELECT * FROM invoices WHERE company_id = ? AND customer_id = ? AND deleted_at IS NULL AND status <> 'Draft' ORDER BY issue_date DESC, id DESC LIMIT 200", [$c['company_id'], $c['customer_id']]);
    }

    public static function invoice(array $c, int $id): array
    {
        $row = App::db()->one("SELECT * FROM invoices WHERE id = ? AND company_id = ? AND customer_id = ? AND deleted_at IS NULL AND status <> 'Draft'", [$id, $c['company_id'], $c['customer_id']]);
        if (!$row) {
            throw new HttpException(404);
        }
        return $row;
    }

    public static function quotes(array $c): array
    {
        return App::db()->all("SELECT * FROM quotes WHERE company_id = ? AND customer_id = ? AND deleted_at IS NULL AND status <> 'Draft' ORDER BY id DESC LIMIT 200", [$c['company_id'], $c['customer_id']]);
    }

    public static function projects(array $c): array
    {
        return App::db()->all('SELECT * FROM projects WHERE company_id = ? AND customer_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 100', [$c['company_id'], $c['customer_id']]);
    }

    public static function project(array $c, int $id): array
    {
        $p = App::db()->one('SELECT * FROM projects WHERE id = ? AND company_id = ? AND customer_id = ? AND deleted_at IS NULL', [$id, $c['company_id'], $c['customer_id']]);
        if (!$p) {
            throw new HttpException(404);
        }
        return $p;
    }

    public static function tickets(array $c): array
    {
        return App::db()->all('SELECT * FROM tickets WHERE company_id = ? AND customer_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 200', [$c['company_id'], $c['customer_id']]);
    }

    public static function ticket(array $c, int $id): array
    {
        $t = App::db()->one('SELECT * FROM tickets WHERE id = ? AND company_id = ? AND customer_id = ? AND deleted_at IS NULL', [$id, $c['company_id'], $c['customer_id']]);
        if (!$t) {
            throw new HttpException(404);
        }
        return $t;
    }

    /** Files explicitly shared with the customer (is_public) on records that belong to this customer. */
    public static function files(array $c): array
    {
        $db = App::db();
        $cid = (int) $c['company_id'];
        $cust = (int) $c['customer_id'];
        return $db->all("SELECT f.* FROM files f WHERE f.company_id = ? AND f.deleted_at IS NULL AND f.is_public = 1 AND (
            (f.entity = 'customers' AND f.record_id = ?)
            OR (f.entity = 'projects' AND f.record_id IN (SELECT id FROM projects WHERE customer_id = ? AND company_id = ? AND deleted_at IS NULL))
            OR (f.entity = 'invoices' AND f.record_id IN (SELECT id FROM invoices WHERE customer_id = ? AND company_id = ? AND deleted_at IS NULL AND status <> 'Draft'))
            OR (f.entity = 'quotes' AND f.record_id IN (SELECT id FROM quotes WHERE customer_id = ? AND company_id = ? AND deleted_at IS NULL AND status <> 'Draft'))
            OR (f.entity = 'tickets' AND f.record_id IN (SELECT id FROM tickets WHERE customer_id = ? AND company_id = ? AND deleted_at IS NULL))
            OR (f.entity = 'contracts' AND f.record_id IN (SELECT id FROM contracts WHERE customer_id = ? AND company_id = ? AND deleted_at IS NULL))
        ) ORDER BY f.id DESC LIMIT 300", [$cid, $cust, $cust, $cid, $cust, $cid, $cust, $cid, $cust, $cid, $cust, $cid]);
    }

    public static function file(array $c, int $id): array
    {
        foreach (self::files($c) as $f) {
            if ((int) $f['id'] === $id) {
                return $f;
            }
        }
        throw new HttpException(404);
    }
}

<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Controller;

final class UserController extends Controller
{
    public function index(Request $r): Response
    {
        $db = App::db();
        $users = $db->all('SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id JOIN company_user cu ON cu.user_id = u.id AND cu.company_id = ? WHERE u.deleted_at IS NULL ORDER BY u.status, u.name', [Auth::companyId()]);
        foreach ($users as &$u) {
            $u['teams'] = $db->column('SELECT t.id FROM teams t JOIN team_user tu ON tu.team_id = t.id WHERE tu.user_id = ? AND t.company_id = ?', [$u['id'], Auth::companyId()]);
            $u['companies'] = $db->column('SELECT company_id FROM company_user WHERE user_id = ?', [$u['id']]);
        }
        return $this->view('settings/users', ['title' => 'Users', 'tab' => 'users', 'users' => $users, 'roles' => $db->all('SELECT id, name, is_admin FROM roles ORDER BY is_admin DESC, name'), 'teams' => $db->all('SELECT id, name FROM teams WHERE company_id = ? ORDER BY name', [Auth::companyId()]), 'companies' => $db->all('SELECT c.id, c.name FROM companies c JOIN company_user cu ON cu.company_id = c.id AND cu.user_id = ? ORDER BY c.name', [Auth::id()])]);
    }

    public function invite(Request $r): Response
    {
        $db = App::db();
        $email = mb_strtolower(trim($r->str('email')));
        $name = mb_substr(trim($r->str('name')), 0, 150);
        $role = $r->int('role_id');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Enter a name and a valid e-mail.');
        }
        if (!$db->value('SELECT id FROM roles WHERE id = ?', [$role])) {
            throw new HttpException(422, 'Choose a role.');
        }
        $existing = $db->one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($existing && !$existing['deleted_at']) {
            if ($db->value('SELECT id FROM company_user WHERE user_id = ? AND company_id = ?', [$existing['id'], Auth::companyId()])) {
                throw new HttpException(422, 'This person is already a user of this company.');
            }
            $db->insert('company_user', ['company_id' => Auth::companyId(), 'user_id' => $existing['id']]);
            Audit::log('user.added_to_company', 'users', (int) $existing['id']);
            return $this->json(['ok' => true, 'message' => 'Existing user added to this company.', 'reload' => true]);
        }
        $id = $db->transaction(function () use ($db, $email, $name, $role, $existing, $r) {
            $data = ['name' => $name, 'email' => $email, 'password' => null, 'role_id' => $role, 'status' => 'invited', 'job_title' => mb_substr($r->str('job_title'), 0, 150) ?: null, 'timezone' => \App\Services\CompanyService::current()['timezone'] ?? 'UTC', 'default_company_id' => Auth::companyId(), 'preferences' => '{"theme":"system","email_notifications":true}', 'updated_at' => DB::now(), 'deleted_at' => null];
            if ($existing) {
                $db->update('users', $data, ['id' => $existing['id']]);
                $id = (int) $existing['id'];
            } else {
                $id = $db->insert('users', $data + ['created_at' => DB::now()]);
            }
            if (!$db->value('SELECT id FROM company_user WHERE user_id = ? AND company_id = ?', [$id, Auth::companyId()])) {
                $db->insert('company_user', ['company_id' => Auth::companyId(), 'user_id' => $id]);
            }
            foreach (array_map('intval', (array) $r->input('teams', [])) as $t) {
                if ($db->value('SELECT id FROM teams WHERE id = ? AND company_id = ?', [$t, Auth::companyId()])) {
                    $db->insert('team_user', ['team_id' => $t, 'user_id' => $id]);
                }
            }
            return $id;
        });
        AuthController::issueToken($id, 'invite', $email, $name);
        Audit::log('user.invited', 'users', $id, ['email' => $email, 'role_id' => $role]);
        return $this->json(['ok' => true, 'message' => "Invitation sent to $email.", 'reload' => true]);
    }

    public function update(Request $r, string $id): Response
    {
        $db = App::db();
        $uid = (int) $id;
        $u = $db->one('SELECT u.* FROM users u JOIN company_user cu ON cu.user_id = u.id AND cu.company_id = ? WHERE u.id = ? AND u.deleted_at IS NULL', [Auth::companyId(), $uid]);
        if (!$u) {
            throw new HttpException(404);
        }
        $role = $r->int('role_id');
        $status = $r->str('status');
        if ($uid === Auth::id() && ($role !== (int) $u['role_id'] || $status !== 'active')) {
            throw new HttpException(422, 'You cannot change your own role or deactivate yourself.');
        }
        if (!$db->value('SELECT id FROM roles WHERE id = ?', [$role])) {
            throw new HttpException(422, 'Invalid role.');
        }
        $data = ['name' => mb_substr(trim($r->str('name')), 0, 150) ?: $u['name'], 'role_id' => $role, 'job_title' => mb_substr($r->str('job_title'), 0, 150) ?: null, 'updated_at' => DB::now()];
        if (in_array($status, ['active', 'disabled'], true) && $u['status'] !== 'invited') {
            $data['status'] = $status;
        }
        if ($r->input('delete')) {
            if ($uid === Auth::id()) {
                throw new HttpException(422, 'You cannot delete yourself.');
            }
            $data['deleted_at'] = DB::now();
            $data['status'] = 'disabled';
            $data['email'] = 'deleted+' . $uid . '+' . $u['email'];
        }
        $db->transaction(function () use ($db, $uid, $data, $r) {
            $db->update('users', $data, ['id' => $uid]);
            $db->query('DELETE FROM team_user WHERE user_id = ? AND team_id IN (SELECT id FROM teams WHERE company_id = ?)', [$uid, Auth::companyId()]);
            foreach (array_map('intval', (array) $r->input('teams', [])) as $t) {
                if ($db->value('SELECT id FROM teams WHERE id = ? AND company_id = ?', [$t, Auth::companyId()])) {
                    $db->insert('team_user', ['team_id' => $t, 'user_id' => $uid]);
                }
            }
            // An administrator can only grant or remove access to companies they belong to themselves.
            $mine = array_map('intval', Auth::user()['company_ids'] ?? [Auth::companyId()]);
            $companies = array_values(array_intersect(array_map('intval', (array) $r->input('companies', [])), $mine));
            if ($r->input('companies') !== null) {
                $in = implode(',', $mine);
                $db->query("DELETE FROM company_user WHERE user_id = ? AND company_id IN ($in)", [$uid]);
                foreach (array_unique(array_merge($companies, [Auth::companyId()])) as $c) {
                    $db->insert('company_user', ['company_id' => $c, 'user_id' => $uid]);
                }
            }
        });
        if (($data['status'] ?? '') === 'disabled') {
            Auth::logoutEverywhere($uid);
        }
        Audit::log('user.updated', 'users', $uid, array_diff_key($data, ['updated_at' => 1]));
        return $this->json(['ok' => true, 'message' => !empty($data['deleted_at']) ? 'User removed.' : 'User updated.', 'reload' => true]);
    }

    /** A user who belongs to the company the administrator is working in (prevents cross-company IDOR). */
    private function companyUser(string $id, string $extra = ''): array
    {
        $u = App::db()->one('SELECT u.* FROM users u JOIN company_user cu ON cu.user_id = u.id AND cu.company_id = ? WHERE u.id = ? AND u.deleted_at IS NULL' . $extra, [Auth::companyId(), (int) $id]);
        if (!$u) {
            throw new HttpException(404);
        }
        return $u;
    }

    public function reset2fa(Request $r, string $id): Response
    {
        $this->companyUser($id);
        App::db()->update('users', ['two_factor_enabled' => 0, 'two_factor_secret' => null, 'recovery_codes' => null], ['id' => (int) $id]);
        Audit::log('user.2fa_reset', 'users', (int) $id);
        return $this->json(['ok' => true, 'message' => 'Two-factor authentication reset for this user.', 'reload' => true]);
    }

    public function logoutAll(Request $r, string $id): Response
    {
        $this->companyUser($id);
        $n = Auth::logoutEverywhere((int) $id);
        Audit::log('user.sessions_revoked', 'users', (int) $id);
        return $this->json(['ok' => true, 'message' => "$n session(s) signed out."]);
    }

    public function resendInvite(Request $r, string $id): Response
    {
        $u = $this->companyUser($id, " AND u.status = 'invited'");
        AuthController::issueToken((int) $u['id'], 'invite', $u['email'], $u['name']);
        return $this->json(['ok' => true, 'message' => 'Invitation re-sent.']);
    }
}

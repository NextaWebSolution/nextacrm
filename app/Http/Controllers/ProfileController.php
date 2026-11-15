<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;

final class ProfileController extends Controller
{
    public function show(Request $r): Response
    {
        return $this->view('profile/show', ['title' => 'My profile', 'u' => App::db()->find('users', Auth::id())]);
    }

    public function update(Request $r): Response
    {
        $name = mb_substr(trim($r->str('name')), 0, 150);
        $tz = $r->str('timezone');
        if ($name === '') {
            return $this->back('error', 'Name is required.');
        }
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        $prefs = Auth::user()['prefs'];
        $prefs['email_notifications'] = (bool) $r->input('email_notifications', false);
        App::db()->update('users', ['name' => $name, 'phone' => mb_substr($r->str('phone'), 0, 50) ?: null, 'job_title' => mb_substr($r->str('job_title'), 0, 150) ?: null, 'timezone' => $tz, 'preferences' => json_encode($prefs), 'updated_at' => DB::now()], ['id' => Auth::id()]);
        return $this->back('success', 'Profile updated.');
    }

    public function password(Request $r): Response
    {
        $u = App::db()->find('users', Auth::id());
        if (!password_verify((string) $r->input('current_password', ''), (string) $u['password'])) {
            return $this->back('error', 'Your current password is incorrect.');
        }
        $new = (string) $r->input('password', '');
        if ($new !== (string) $r->input('password_confirmation', '')) {
            return $this->back('error', 'The new passwords do not match.');
        }
        if ($err = Auth::validatePassword($new, $u['email'])) {
            return $this->back('error', $err);
        }
        App::db()->update('users', ['password' => Crypto::hashPassword($new), 'updated_at' => DB::now()], ['id' => $u['id']]);
        $n = Auth::logoutEverywhere((int) $u['id'], true);
        Session::regenerate();
        Audit::log('auth.password_changed', 'users', (int) $u['id']);
        return $this->back('success', 'Password changed.' . ($n ? " Signed out $n other session(s)." : ''));
    }

    public function security(Request $r): Response
    {
        $u = App::db()->find('users', Auth::id());
        $sessions = App::db()->all('SELECT * FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 20', [Auth::id()]);
        $pending = Session::get('2fa_setup_secret');
        return $this->view('profile/security', [
            'title' => 'Security', 'u' => $u, 'sessions' => $sessions, 'currentSession' => Auth::user()['session_id'] ?? 0,
            'pendingSecret' => $pending, 'otpUri' => $pending ? Totp::uri($pending, $u['email'], (string) setting('app.name', 'NextaCRM')) : null,
            'recovery' => Session::pull('2fa_recovery_codes'),
        ]);
    }

    public function setup2fa(Request $r): Response
    {
        Session::put('2fa_setup_secret', Totp::generateSecret());
        return Response::redirect('/profile/security');
    }

    public function enable2fa(Request $r): Response
    {
        $secret = (string) Session::get('2fa_setup_secret', '');
        if ($secret === '') {
            return $this->redirect('/profile/security', 'error', 'Start the setup again.');
        }
        $step = Totp::verify($secret, $r->str('code'));
        if ($step === null) {
            return $this->redirect('/profile/security', 'error', 'That code is not valid. Check the time on your phone and try again.');
        }
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $c = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $hashes[] = password_hash($c, PASSWORD_DEFAULT);
        }
        App::db()->update('users', ['two_factor_enabled' => 1, 'two_factor_secret' => Crypto::encrypt($secret), 'two_factor_last_step' => $step, 'recovery_codes' => json_encode($hashes), 'updated_at' => DB::now()], ['id' => Auth::id()]);
        Session::forget('2fa_setup_secret');
        Session::put('2fa_recovery_codes', $codes);
        Audit::log('auth.2fa_enabled', 'users', Auth::id());
        return $this->redirect('/profile/security', 'success', 'Two-factor authentication is on. Save your recovery codes.');
    }

    public function disable2fa(Request $r): Response
    {
        $u = App::db()->find('users', Auth::id());
        if (!password_verify((string) $r->input('password', ''), (string) $u['password'])) {
            return $this->redirect('/profile/security', 'error', 'Password is incorrect.');
        }
        if (setting('security.enforce_2fa_admins', false) && Auth::user()['is_admin']) {
            return $this->redirect('/profile/security', 'error', 'Two-factor authentication is required for administrators.');
        }
        App::db()->update('users', ['two_factor_enabled' => 0, 'two_factor_secret' => null, 'recovery_codes' => null, 'two_factor_last_step' => null], ['id' => Auth::id()]);
        Audit::log('auth.2fa_disabled', 'users', Auth::id());
        return $this->redirect('/profile/security', 'success', 'Two-factor authentication turned off.');
    }

    public function revokeSessions(Request $r): Response
    {
        $id = $r->int('session_id');
        if ($id) {
            App::db()->query('UPDATE user_sessions SET revoked_at = ? WHERE id = ? AND user_id = ?', [DB::now(), $id, Auth::id()]);
            return $this->back('success', 'Session signed out.');
        }
        $n = Auth::logoutEverywhere(Auth::id(), true);
        return $this->back('success', "Signed out of $n other session(s).");
    }

    /** Small UI preferences stored on the user (theme, sidebar, columns, per page). */
    public function preferences(Request $r): Response
    {
        $prefs = Auth::user()['prefs'];
        $theme = $r->input('theme');
        if (in_array($theme, ['light', 'dark', 'system'], true)) {
            $prefs['theme'] = $theme;
        }
        if ($r->input('sidebar') !== null) {
            $prefs['sidebar_collapsed'] = (bool) $r->input('sidebar');
        }
        $entity = $r->str('entity');
        if ($entity !== '' && \App\Services\EntityRegistry::exists($entity) && is_array($r->input('columns'))) {
            $prefs['columns'][$entity] = array_slice(array_values(array_filter($r->input('columns'), 'is_string')), 0, 30);
        }
        if ($r->input('per_page') !== null) {
            $prefs['per_page'] = max(10, min(100, (int) $r->input('per_page')));
        }
        App::db()->update('users', ['preferences' => json_encode($prefs)], ['id' => Auth::id()]);
        return $this->json(['ok' => true]);
    }

    public function switchCompany(Request $r): Response
    {
        $cid = $r->int('company_id');
        if (!in_array($cid, Auth::user()['company_ids'], true)) {
            throw new HttpException(403);
        }
        Session::put('company_id', $cid);
        App::db()->update('users', ['default_company_id' => $cid], ['id' => Auth::id()]);
        return Response::redirect('/');
    }
}

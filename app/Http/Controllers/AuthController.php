<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function showLogin(Request $r): Response
    {
        return $this->view('auth/login', ['title' => 'Sign in'], 'guest');
    }

    public function login(Request $r): Response
    {
        $email = $r->str('email');
        $res = Auth::attempt($email, (string) $r->input('password', ''));
        $this->withOld(['email' => $email]);
        return match ($res['status']) {
            'ok' => $this->afterLogin(),
            '2fa' => Response::redirect('/login/2fa'),
            'locked' => $this->redirect('/login', 'error', 'Too many failed attempts. Please wait 15 minutes and try again.'),
            default => $this->redirect('/login', 'error', 'The e-mail or password is incorrect.'),
        };
    }

    private function afterLogin(): Response
    {
        Session::forget('_old');
        $intended = (string) Session::pull('intended', '/');
        return Response::redirect(Response::isSafePath($intended) && !str_starts_with($intended, '/x/') ? $intended : '/');
    }

    public function show2fa(Request $r): Response
    {
        if (!Session::get('2fa_uid')) {
            return Response::redirect('/login');
        }
        return $this->view('auth/two_factor', ['title' => 'Two-factor authentication'], 'guest');
    }

    public function verify2fa(Request $r): Response
    {
        $res = Auth::attemptTwoFactor($r->str('code'));
        return match ($res['status']) {
            'ok' => $this->afterLogin(),
            'expired' => $this->redirect('/login', 'error', 'Your sign-in expired. Please sign in again.'),
            'locked' => $this->redirect('/login', 'error', 'Too many invalid codes. Please wait 15 minutes.'),
            default => $this->redirect('/login/2fa', 'error', 'That code is not valid. Try again or use a recovery code.'),
        };
    }

    public function logout(Request $r): Response
    {
        Auth::logout();
        Session::start();
        Session::flash('success', 'You have been signed out.');
        return Response::redirect('/login');
    }

    public function showForgot(Request $r): Response
    {
        return $this->view('auth/forgot', ['title' => 'Reset password'], 'guest');
    }

    public function sendReset(Request $r): Response
    {
        $email = mb_strtolower($r->str('email'));
        $user = App::db()->one("SELECT * FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL", [$email]);
        if ($user) {
            self::issueToken((int) $user['id'], 'reset', $user['email'], $user['name']);
        }
        // Same response whether or not the account exists (no user enumeration).
        return $this->redirect('/login', 'success', 'If an account exists for that e-mail, a reset link has been sent.');
    }

    public static function issueToken(int $userId, string $purpose, string $email, string $name): string
    {
        $token = Crypto::token(32);
        $hours = $purpose === 'invite' ? 72 : 1;
        $db = App::db();
        $db->query('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [DB::now(), $userId]);
        $db->insert('password_resets', ['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $hours * 3600), 'created_at' => DB::now()]);
        $link = full_url('/reset-password/' . $token);
        $app = e((string) setting('app.name', 'NextaCRM'));
        if ($purpose === 'invite') {
            $inviter = e(Auth::user()['name'] ?? 'Your administrator');
            $body = "<p>Hi " . e($name) . ",</p><p>$inviter invited you to <strong>$app</strong>. Click below to set your password and activate your account. The link is valid for 72 hours.</p>";
            $subject = "You're invited to $app";
        } else {
            $body = "<p>Hi " . e($name) . ",</p><p>We received a request to reset your password. The link is valid for 1 hour. If you didn't request this, you can ignore this e-mail.</p>";
            $subject = "Reset your $app password";
        }
        $body .= '<p><a href="' . e($link) . '" style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">' . ($purpose === 'invite' ? 'Accept invitation' : 'Choose a new password') . '</a></p><p style="font-size:12px;color:#6b7280">' . e($link) . '</p>';
        Mailer::queue($email, $subject, $body);
        return $token;
    }

    private function findToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $token)) {
            return null;
        }
        return App::db()->one('SELECT pr.*, u.email, u.name, u.status FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ? AND u.deleted_at IS NULL', [hash('sha256', $token), DB::now()]);
    }

    public function showReset(Request $r, string $token): Response
    {
        $row = $this->findToken($token);
        if (!$row) {
            return $this->redirect('/forgot-password', 'error', 'This link is invalid or has expired. Request a new one.');
        }
        return $this->view('auth/reset', ['title' => $row['purpose'] === 'invite' ? 'Activate your account' : 'Choose a new password', 'token' => $token, 'row' => $row], 'guest');
    }

    public function reset(Request $r, string $token): Response
    {
        $row = $this->findToken($token);
        if (!$row) {
            return $this->redirect('/forgot-password', 'error', 'This link is invalid or has expired.');
        }
        $pw = (string) $r->input('password', '');
        if ($pw !== (string) $r->input('password_confirmation', '')) {
            return $this->redirect('/reset-password/' . $token, 'error', 'The passwords do not match.');
        }
        if ($err = Auth::validatePassword($pw, $row['email'])) {
            return $this->redirect('/reset-password/' . $token, 'error', $err);
        }
        $db = App::db();
        $data = ['password' => Crypto::hashPassword($pw), 'updated_at' => DB::now()];
        if ($row['purpose'] === 'invite') {
            $data['status'] = 'active';
            $data['email_verified_at'] = DB::now();
        }
        $db->update('users', $data, ['id' => $row['user_id']]);
        $db->update('password_resets', ['used_at' => DB::now()], ['id' => $row['id']]);
        Auth::logoutEverywhere((int) $row['user_id']);
        Audit::log('auth.password_reset', 'users', (int) $row['user_id'], null, (int) $row['user_id']);
        return $this->redirect('/login', 'success', $row['purpose'] === 'invite' ? 'Your account is active. Sign in to continue.' : 'Password updated. Sign in with your new password.');
    }
}

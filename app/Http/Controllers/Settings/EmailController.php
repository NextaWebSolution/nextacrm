<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Http\Controllers\Controller;

final class EmailController extends Controller
{
    public function index(Request $r): Response
    {
        $queue = App::db()->all('SELECT id, to_email, subject, status, attempts, error, created_at, sent_at FROM email_queue ORDER BY id DESC LIMIT 25');
        return $this->view('settings/email', ['title' => 'E-mail delivery', 'tab' => 'email', 'queue' => $queue, 'hasPassword' => Settings::get('mail.smtp_password', '') !== '']);
    }

    public function save(Request $r): Response
    {
        $values = [
            'mail.driver' => in_array($r->str('driver'), ['smtp', 'mail'], true) ? $r->str('driver') : 'smtp',
            'mail.from_email' => filter_var($r->str('from_email'), FILTER_VALIDATE_EMAIL) ?: '',
            'mail.from_name' => mb_substr($r->str('from_name'), 0, 100),
            'mail.smtp_host' => mb_substr(preg_replace('/[^A-Za-z0-9.\-]/', '', $r->str('smtp_host')) ?? '', 0, 190),
            'mail.smtp_port' => max(1, min(65535, $r->int('smtp_port', 587))),
            'mail.smtp_encryption' => in_array($r->str('smtp_encryption'), ['tls', 'ssl', 'none'], true) ? $r->str('smtp_encryption') : 'tls',
            'mail.smtp_username' => mb_substr($r->str('smtp_username'), 0, 190),
        ];
        if ($r->str('smtp_password') !== '') {
            $values['mail.smtp_password'] = $r->str('smtp_password');
        }
        Settings::setMany($values);
        Audit::log('settings.email', null, null, array_diff_key($values, ['mail.smtp_password' => 1]));
        return $this->back('success', 'E-mail settings saved.');
    }

    public function test(Request $r): Response
    {
        $to = filter_var($r->str('to'), FILTER_VALIDATE_EMAIL) ?: Auth::user()['email'];
        $err = Mailer::send($to, 'Test e-mail from ' . setting('app.name', 'NextaCRM'), '<p>This is a test message. If you can read it, outgoing e-mail is configured correctly.</p>');
        return $this->json(['ok' => $err === null, 'message' => $err === null ? "Test e-mail sent to $to." : 'Sending failed: ' . $err]);
    }
}

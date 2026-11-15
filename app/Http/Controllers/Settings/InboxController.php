<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\InboxService;

/** IMAP mailboxes that feed tickets and leads. Passwords are encrypted and never returned. */
final class InboxController extends Controller
{
    public function index(Request $r): Response
    {
        $boxes = App::db()->all('SELECT id, name, host, port, encryption, username, folder, purpose, is_active, last_checked_at, last_error FROM mailboxes WHERE company_id = ? ORDER BY id', [Auth::companyId()]);
        $edit = null;
        if ($r->int('edit')) {
            foreach ($boxes as $b) {
                if ((int) $b['id'] === $r->int('edit')) {
                    $edit = $b;
                }
            }
        }
        return $this->view('settings/inbox', ['title' => 'Inbound e-mail', 'tab' => 'inbox', 'boxes' => $boxes, 'edit' => $edit]);
    }

    private function input(Request $r, ?array $old): array
    {
        $host = strtolower(trim($r->str('host')));
        if (!preg_match('/^[a-z0-9.\-]{3,190}$/', $host)) {
            throw new HttpException(422, 'Enter a valid IMAP host name, e.g. mail.example.com.');
        }
        $data = [
            'name' => mb_substr(trim($r->str('name')), 0, 100) ?: $host,
            'host' => $host, 'port' => max(1, min(65535, $r->int('port', 993))),
            'encryption' => in_array($r->str('encryption'), ['ssl', 'tls'], true) ? $r->str('encryption') : 'ssl',
            'username' => mb_substr(trim($r->str('username')), 0, 190),
            'folder' => mb_substr(trim($r->str('folder')) ?: 'INBOX', 0, 100),
            'purpose' => $r->str('purpose') === 'leads' ? 'leads' : 'tickets',
            'is_active' => $r->input('is_active') ? 1 : 0, 'updated_at' => DB::now(),
        ];
        if ($r->str('password') !== '') {
            $data['password'] = Crypto::encrypt($r->str('password'));
        } elseif (!$old) {
            throw new HttpException(422, 'Enter the mailbox password.');
        }
        return $data;
    }

    public function save(Request $r): Response
    {
        $db = App::db();
        $id = $r->int('id');
        $old = $id ? $db->one('SELECT * FROM mailboxes WHERE id = ? AND company_id = ?', [$id, Auth::companyId()]) : null;
        if ($id && !$old) {
            throw new HttpException(404);
        }
        $data = $this->input($r, $old);
        if ($old) {
            $db->update('mailboxes', $data, ['id' => $id]);
        } else {
            $id = $db->insert('mailboxes', $data + ['company_id' => Auth::companyId(), 'created_at' => DB::now()]);
        }
        Audit::log('settings.mailbox', null, $id, ['host' => $data['host'], 'purpose' => $data['purpose']]);
        return $this->redirect('/settings/inbox', 'success', 'Mailbox saved. New messages are fetched every time the scheduler runs.');
    }

    public function test(Request $r, string $id): Response
    {
        $mb = App::db()->one('SELECT * FROM mailboxes WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        if (!$mb) {
            throw new HttpException(404);
        }
        try {
            $c = InboxService::client($mb);
            $n = count($c->unseen(500));
            $c->logout();
            return $this->json(['ok' => true, 'message' => "Connected. $n unread message(s) waiting."]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'message' => 'Connection failed: ' . $e->getMessage()], 422);
        }
    }

    public function fetchNow(Request $r, string $id): Response
    {
        $mb = App::db()->one('SELECT * FROM mailboxes WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        if (!$mb) {
            throw new HttpException(404);
        }
        $res = InboxService::poll($mb);
        return $this->json(['ok' => !str_starts_with($res, 'error'), 'message' => 'Fetch: ' . $res, 'reload' => true]);
    }

    public function delete(Request $r, string $id): Response
    {
        App::db()->query('DELETE FROM mailboxes WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        return $this->json(['ok' => true, 'message' => 'Mailbox removed.', 'reload' => true]);
    }
}

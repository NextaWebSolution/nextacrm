<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Events;
use App\Core\HttpException;
use App\Core\Mailer;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;

final class TicketController extends Controller
{
    public function reply(Request $r, string $id): Response
    {
        $svc = $this->service('tickets');
        $t = $svc->find((int) $id);
        if (!Permissions::can('tickets', 'edit', $t)) {
            throw new HttpException(403);
        }
        $body = trim($r->str('body'));
        if ($body === '') {
            throw new HttpException(422, 'Write a reply first.');
        }
        $internal = (bool) $r->input('internal', false);
        $db = App::db();
        $db->transaction(function () use ($db, $t, $body, $internal, $r) {
            $db->insert('ticket_replies', ['ticket_id' => $t['id'], 'user_id' => Auth::id(), 'body' => mb_substr($body, 0, 20000), 'is_internal' => $internal ? 1 : 0, 'author_name' => Auth::user()['name'], 'author_email' => Auth::user()['email'], 'created_at' => DB::now()]);
            $update = [];
            if (!$internal && empty($t['first_responded_at'])) {
                $update['first_responded_at'] = DB::now();
            }
            $status = $r->str('status');
            if ($status !== '' && $status !== $t['status']) {
                $update['status'] = $status;
            } elseif (!$internal && $t['status'] === 'Open') {
                $update['status'] = 'In Progress';
            }
            if ($update) {
                RecordServiceProxy::update((int) $t['id'], $update);
            }
        });
        if (!$internal) {
            $to = $t['requester_email'] ?: ($t['contact_id'] ? $db->value('SELECT email FROM contacts WHERE id = ?', [$t['contact_id']]) : null);
            if ($to && $r->input('notify', '1') === '1') {
                Mailer::queue((string) $to, '[' . $t['number'] . '] ' . $t['subject'], nl2br_safe($body) . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0"><p style="font-size:12px;color:#6b7280">Ticket ' . e($t['number']) . ' · Reply to this e-mail or contact us to follow up.</p>', [], \App\Services\InboxService::replyAddress() ?? Auth::user()['email']);
            }
        }
        return $this->json(['ok' => true, 'message' => $internal ? 'Internal note added' : 'Reply sent', 'reload' => true]);
    }
}

/** Small helper so the reply flow reuses record validation/hooks for status changes. */
final class RecordServiceProxy
{
    public static function update(int $id, array $data): void
    {
        $sys = array_intersect_key($data, array_flip(['first_responded_at']));
        if ($sys) {
            App::db()->update('tickets', $sys, ['id' => $id]);
        }
        $rest = array_diff_key($data, $sys);
        if ($rest) {
            \App\Services\RecordService::for('tickets')->update($id, $rest);
        }
    }
}

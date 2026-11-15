<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\ErrorHandler;
use App\Core\Mail\ImapClient;
use App\Core\Mail\MimeParser;

/**
 * Polls IMAP mailboxes and turns new messages into tickets (or ticket replies) and leads.
 * Replies are matched by the ticket number in the subject, e.g. "Re: [TKT-0007] Printer offline".
 */
final class InboxService
{
    private const PER_RUN = 25;

    public static function run(): array
    {
        $report = [];
        $db = App::db();
        foreach ($db->all('SELECT * FROM mailboxes WHERE is_active = 1 ORDER BY id') as $mb) {
            $report[$mb['name']] = Auth::actingAs(0, (int) $mb['company_id'], fn () => self::poll($mb)) ?? 'company not found';
        }
        return $report;
    }

    public static function client(array $mb): ImapClient
    {
        $c = new ImapClient((string) $mb['host'], (int) $mb['port'], (string) $mb['encryption']);
        $c->connect();
        $c->login((string) $mb['username'], Crypto::decrypt((string) $mb['password']));
        $c->select((string) ($mb['folder'] ?: 'INBOX'));
        return $c;
    }

    public static function poll(array $mb): string
    {
        $db = App::db();
        $n = 0;
        try {
            $imap = self::client($mb);
            foreach ($imap->unseen(self::PER_RUN) as $uid) {
                $raw = $imap->fetch($uid);
                if ($raw !== '') {
                    try {
                        $n += self::ingest($mb, $raw) ? 1 : 0;
                    } catch (\Throwable $e) {
                        ErrorHandler::report($e);
                    }
                }
                $imap->markSeen($uid);
            }
            $imap->logout();
            $db->update('mailboxes', ['last_checked_at' => DB::now(), 'last_error' => null], ['id' => $mb['id']]);
            return "$n message(s)";
        } catch (\Throwable $e) {
            $db->update('mailboxes', ['last_checked_at' => DB::now(), 'last_error' => mb_substr($e->getMessage(), 0, 500)], ['id' => $mb['id']]);
            return 'error: ' . $e->getMessage();
        }
    }

    /** Processes one raw message. Returns false when skipped (duplicate, auto-reply, own address). */
    public static function ingest(array $mb, string $raw): bool
    {
        $db = App::db();
        $msg = MimeParser::parse($raw);
        $hash = hash('sha256', $msg['message_id'] !== '' ? $msg['message_id'] : $raw);
        if ($db->value('SELECT id FROM inbound_messages WHERE mailbox_id = ? AND message_hash = ?', [$mb['id'], $hash])) {
            return false;
        }
        $from = $msg['from_email'];
        $own = array_filter([strtolower((string) $mb['username']), strtolower((string) setting('mail.from_email', ''))]);
        if ($from === '' || $msg['auto'] || in_array($from, $own, true)) {
            $db->insert('inbound_messages', ['company_id' => $mb['company_id'], 'mailbox_id' => $mb['id'], 'message_hash' => $hash, 'from_email' => $from ?: null, 'subject' => $msg['subject'], 'received_at' => DB::now()]);
            return false;
        }
        SequenceService::stopForReply($from);
        [$entity, $id] = $mb['purpose'] === 'leads' ? self::toLead($msg) : self::toTicket($msg);
        foreach ($msg['attachments'] as $att) {
            $tmp = NX_ROOT . '/storage/tmp/mail-' . bin2hex(random_bytes(8));
            file_put_contents($tmp, $att['data']);
            try {
                FileService::storeLocal($tmp, $att['name'], $entity, $id);
            } catch (\Throwable $e) {
                ActivityService::log($entity, $id, 'system', 'Attachment "' . mb_substr($att['name'], 0, 120) . '" was not stored: ' . $e->getMessage());
            } finally {
                @unlink($tmp);
            }
        }
        $db->insert('inbound_messages', ['company_id' => $mb['company_id'], 'mailbox_id' => $mb['id'], 'message_hash' => $hash, 'from_email' => $from, 'subject' => $msg['subject'], 'entity' => $entity, 'record_id' => $id, 'received_at' => DB::now()]);
        return true;
    }

    private static function toTicket(array $msg): array
    {
        $db = App::db();
        $cid = Auth::companyId();
        $prefix = (string) ($db->value('SELECT ticket_prefix FROM companies WHERE id = ?', [$cid]) ?: 'TKT-');
        $body = $msg['text'] !== '' ? $msg['text'] : '(no message body)';
        if (preg_match('/\[(' . preg_quote($prefix, '/') . '\d+)\]/', $msg['subject'], $m)) {
            $t = $db->one('SELECT * FROM tickets WHERE company_id = ? AND number = ? AND deleted_at IS NULL', [$cid, $m[1]]);
            if ($t) {
                $db->insert('ticket_replies', ['ticket_id' => $t['id'], 'user_id' => null, 'body' => mb_substr($body, 0, 20000), 'is_internal' => 0, 'author_name' => $msg['from_name'] ?: $msg['from_email'], 'author_email' => $msg['from_email'], 'created_at' => DB::now()]);
                if (in_array($t['status'], ['Waiting on Customer', 'Resolved'], true)) {
                    $db->update('tickets', ['status' => 'Open', 'updated_at' => DB::now()], ['id' => $t['id']]);
                }
                if ($t['assigned_to']) {
                    NotificationService::notify((int) $t['assigned_to'], 'ticket.reply', 'E-mail reply on ' . $t['number'], str_limit($body, 140), '/r/tickets/' . $t['id']);
                }
                return ['tickets', (int) $t['id']];
            }
        }
        $contact = $db->one('SELECT id, customer_id FROM contacts WHERE company_id = ? AND LOWER(email) = ? AND deleted_at IS NULL LIMIT 1', [$cid, $msg['from_email']]);
        $t = RecordService::for('tickets')->create(array_filter([
            'subject' => $msg['subject'] !== '' ? $msg['subject'] : '(no subject)',
            'description' => mb_substr($body, 0, 20000), 'channel' => 'Email', 'priority' => 'Medium',
            'requester_name' => $msg['from_name'] ?: $msg['from_email'], 'requester_email' => $msg['from_email'],
            'contact_id' => $contact ? (string) $contact['id'] : null, 'customer_id' => $contact && $contact['customer_id'] ? (string) $contact['customer_id'] : null,
        ], fn ($v) => $v !== null), ['skip_permission' => true, 'system' => true]);
        return ['tickets', (int) $t['id']];
    }

    private static function toLead(array $msg): array
    {
        $db = App::db();
        $existing = $db->one('SELECT id FROM leads WHERE company_id = ? AND LOWER(email) = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [Auth::companyId(), $msg['from_email']]);
        if ($existing) {
            ActivityService::log('leads', (int) $existing['id'], 'email', mb_substr($msg['text'], 0, 20000), 'Received: ' . $msg['subject']);
            return ['leads', (int) $existing['id']];
        }
        $name = $msg['from_name'] !== '' ? $msg['from_name'] : ucwords(str_replace(['.', '_', '-'], ' ', strtok($msg['from_email'], '@')));
        $lead = RecordService::for('leads')->create([
            'name' => mb_substr($name, 0, 150), 'email' => $msg['from_email'], 'source' => 'Email', 'status' => 'New',
            'description' => mb_substr(trim(($msg['subject'] !== '' ? $msg['subject'] . "\n\n" : '') . $msg['text']), 0, 20000),
        ], ['skip_permission' => true, 'system' => true]);
        return ['leads', (int) $lead['id']];
    }

    /** Address customers should reply to for tickets in the current company (a tickets mailbox), if any. */
    public static function replyAddress(): ?string
    {
        $u = App::db()->value("SELECT username FROM mailboxes WHERE company_id = ? AND purpose = 'tickets' AND is_active = 1 ORDER BY id LIMIT 1", [Auth::companyId()]);
        return $u && filter_var($u, FILTER_VALIDATE_EMAIL) ? (string) $u : null;
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Mailer;

/**
 * Drip e-mail sequences for leads and contacts. Each enrolment walks the steps (delay in days → e-mail),
 * stops on reply (via the IMAP inbox), on unsubscribe, or when the record disappears.
 * Unsubscribe links are HMAC-signed per company + address.
 */
final class SequenceService
{
    public const ENTITIES = ['leads' => 'Leads', 'contacts' => 'Contacts'];

    public static function options(): array
    {
        $out = [];
        foreach (App::db()->all('SELECT id, name FROM email_sequences WHERE company_id = ? AND is_active = 1 ORDER BY name', [Auth::companyId()]) as $s) {
            $out[(int) $s['id']] = $s['name'];
        }
        return $out;
    }

    public static function steps(int $sequenceId): array
    {
        return App::db()->all('SELECT * FROM sequence_steps WHERE sequence_id = ? ORDER BY sort, id', [$sequenceId]);
    }

    public static function isUnsubscribed(string $email): bool
    {
        return (bool) App::db()->value('SELECT id FROM email_unsubscribes WHERE company_id = ? AND email = ?', [Auth::companyId(), mb_strtolower($email)]);
    }

    public static function enroll(int $sequenceId, string $entity, int $recordId): string
    {
        $db = App::db();
        $seq = $db->one('SELECT * FROM email_sequences WHERE id = ? AND company_id = ? AND is_active = 1', [$sequenceId, Auth::companyId()]);
        if (!$seq || $seq['entity'] !== $entity) {
            throw new HttpException(422, 'Choose an active sequence for ' . strtolower(self::ENTITIES[$entity] ?? $entity) . '.');
        }
        $rec = $db->one('SELECT * FROM ' . $db->ident($entity) . ' WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [$recordId, Auth::companyId()]);
        $email = mb_strtolower(trim((string) ($rec['email'] ?? '')));
        if (!$rec || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'This record has no valid e-mail address.');
        }
        if (self::isUnsubscribed($email)) {
            throw new HttpException(422, $email . ' has unsubscribed from e-mails.');
        }
        $steps = self::steps($sequenceId);
        if (!$steps) {
            throw new HttpException(422, 'This sequence has no steps yet.');
        }
        $existing = $db->one('SELECT * FROM sequence_enrollments WHERE sequence_id = ? AND entity = ? AND record_id = ?', [$sequenceId, $entity, $recordId]);
        $next = gmdate('Y-m-d H:i:s', time() + (int) $steps[0]['delay_days'] * 86400);
        if ($existing) {
            if ($existing['status'] === 'active') {
                return 'Already enrolled.';
            }
            $db->update('sequence_enrollments', ['status' => 'active', 'current_step' => 0, 'next_run_at' => $next, 'email' => $email, 'updated_at' => DB::now()], ['id' => $existing['id']]);
        } else {
            $db->insert('sequence_enrollments', ['company_id' => Auth::companyId(), 'sequence_id' => $sequenceId, 'entity' => $entity, 'record_id' => $recordId, 'email' => $email, 'status' => 'active', 'current_step' => 0, 'next_run_at' => $next, 'enrolled_by' => Auth::id() ?: null, 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        }
        ActivityService::log($entity, $recordId, 'system', 'Enrolled in e-mail sequence "' . $seq['name'] . '".');
        return 'Enrolled in "' . $seq['name'] . '".';
    }

    public static function stop(int $enrollmentId, string $status = 'stopped'): void
    {
        App::db()->query('UPDATE sequence_enrollments SET status = ?, updated_at = ? WHERE id = ? AND company_id = ?', [$status, DB::now(), $enrollmentId, Auth::companyId()]);
    }

    /** Called by the inbox when someone writes in: stop sequences that are configured to stop on reply. */
    public static function stopForReply(string $email): void
    {
        $db = App::db();
        $db->query("UPDATE sequence_enrollments SET status = 'replied', updated_at = ? WHERE company_id = ? AND email = ? AND status = 'active' AND sequence_id IN (SELECT id FROM email_sequences WHERE stop_on_reply = 1)", [DB::now(), Auth::companyId(), mb_strtolower($email)]);
    }

    public static function unsubscribeToken(int $companyId, string $email): string
    {
        $mac = hash_hmac('sha256', 'unsub|' . $companyId . '|' . mb_strtolower($email), (string) App::config('app_key'));
        return rtrim(strtr(base64_encode($companyId . '|' . mb_strtolower($email) . '|' . substr($mac, 0, 32)), '+/', '-_'), '=');
    }

    /** @return array{0:int,1:string}|null company id and e-mail */
    public static function parseToken(string $token): ?array
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || substr_count($raw, '|') !== 2) {
            return null;
        }
        [$cid, $email, $mac] = explode('|', $raw);
        $expected = substr(hash_hmac('sha256', 'unsub|' . (int) $cid . '|' . $email, (string) App::config('app_key')), 0, 32);
        return ctype_digit($cid) && hash_equals($expected, $mac) && filter_var($email, FILTER_VALIDATE_EMAIL) ? [(int) $cid, $email] : null;
    }

    public static function unsubscribe(int $companyId, string $email): void
    {
        $db = App::db();
        try {
            $db->insert('email_unsubscribes', ['company_id' => $companyId, 'email' => mb_strtolower($email), 'created_at' => DB::now()]);
        } catch (\PDOException) {
            // already unsubscribed
        }
        $db->query("UPDATE sequence_enrollments SET status = 'unsubscribed', updated_at = ? WHERE company_id = ? AND email = ? AND status = 'active'", [DB::now(), $companyId, mb_strtolower($email)]);
    }

    /** Cron (per company): sends due steps. */
    public static function run(): int
    {
        $db = App::db();
        $sent = 0;
        $company = CompanyService::current();
        $due = $db->all("SELECT e.*, s.name AS seq_name, s.is_active AS seq_active FROM sequence_enrollments e JOIN email_sequences s ON s.id = e.sequence_id WHERE e.company_id = ? AND e.status = 'active' AND e.next_run_at <= ? ORDER BY e.next_run_at LIMIT 100", [Auth::companyId(), DB::now()]);
        foreach ($due as $en) {
            if (!$en['seq_active']) {
                continue;
            }
            $steps = self::steps((int) $en['sequence_id']);
            $step = $steps[(int) $en['current_step']] ?? null;
            $rec = $db->one('SELECT * FROM ' . $db->ident($en['entity']) . ' WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [(int) $en['record_id'], Auth::companyId()]);
            if (!$step || !$rec) {
                $db->update('sequence_enrollments', ['status' => $step ? 'stopped' : 'completed', 'updated_at' => DB::now()], ['id' => $en['id']]);
                continue;
            }
            if (self::isUnsubscribed($en['email'])) {
                $db->update('sequence_enrollments', ['status' => 'unsubscribed', 'updated_at' => DB::now()], ['id' => $en['id']]);
                continue;
            }
            $subject = WorkflowService::render((string) $step['subject'], $en['entity'], $rec);
            $link = full_url('/unsubscribe/' . self::unsubscribeToken(Auth::companyId(), $en['email']));
            $bodyText = WorkflowService::render((string) $step['body'], $en['entity'], $rec);
            $html = nl2br_safe($bodyText)
                . '<p style="margin-top:28px;font-size:11px;color:#9ca3af">' . e($company['name'] ?? '') . (!empty($company['address']) ? ' · ' . e(str_replace("\n", ', ', (string) $company['address'])) : '')
                . '<br><a href="' . e($link) . '" style="color:#9ca3af">Unsubscribe</a> from these e-mails.</p>';
            Mailer::queue($en['email'], $subject, $html, [], InboxService::replyAddress());
            ActivityService::log($en['entity'], (int) $en['record_id'], 'email', strip_tags($bodyText), 'Sequence "' . $en['seq_name'] . '" · step ' . ((int) $en['current_step'] + 1) . ': ' . $subject);
            $nextIdx = (int) $en['current_step'] + 1;
            $next = $steps[$nextIdx] ?? null;
            $db->update('sequence_enrollments', [
                'current_step' => $nextIdx, 'last_sent_at' => DB::now(), 'updated_at' => DB::now(),
                'status' => $next ? 'active' : 'completed',
                'next_run_at' => $next ? gmdate('Y-m-d H:i:s', time() + max(0, (int) $next['delay_days']) * 86400) : null,
            ], ['id' => $en['id']]);
            $sent++;
        }
        return $sent;
    }
}

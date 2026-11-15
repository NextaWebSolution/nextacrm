<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * E-mail is queued in the database and delivered by cron (or immediately when requested).
 */
final class Mailer
{
    public static function queue(string $to, string $subject, string $html, array $attachments = [], ?string $replyTo = null): int
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        $id = App::db()->insert('email_queue', [
            'company_id' => Auth::check() ? Auth::companyId() : null,
            'to_email' => $to,
            'subject' => mb_substr($subject, 0, 250),
            'body' => $html,
            'attachments' => $attachments ? json_encode($attachments) : null,
            'reply_to' => $replyTo,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => DB::now(),
        ]);
        if (!self::cronConfigured()) {
            // Without cron, deliver at the end of the request so the user is not kept waiting.
            register_shutdown_function(static function () use ($id): void {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                self::processQueue(5, $id);
            });
        }
        return $id;
    }

    private static function cronConfigured(): bool
    {
        $last = (int) Settings::get('cron.last_run', 0);
        return $last > time() - 900;
    }

    /** Deliver pending e-mails. Returns number sent. */
    public static function processQueue(int $limit = 25, ?int $onlyId = null): int
    {
        $db = App::db();
        $sql = "SELECT * FROM email_queue WHERE status = 'pending' AND attempts < 5";
        $params = [];
        if ($onlyId) {
            $sql .= ' AND id = ?';
            $params[] = $onlyId;
        }
        $rows = $db->all($sql . ' ORDER BY id LIMIT ' . (int) $limit, $params);
        $sent = 0;
        foreach ($rows as $row) {
            $att = json_decode((string) $row['attachments'], true) ?: [];
            $err = self::send($row['to_email'], $row['subject'], $row['body'], $att, $row['reply_to']);
            if ($err === null) {
                $db->update('email_queue', ['status' => 'sent', 'sent_at' => DB::now(), 'attempts' => (int) $row['attempts'] + 1, 'error' => null], ['id' => $row['id']]);
                $sent++;
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $db->update('email_queue', ['status' => $attempts >= 5 ? 'failed' : 'pending', 'attempts' => $attempts, 'error' => mb_substr($err, 0, 500)], ['id' => $row['id']]);
            }
        }
        return $sent;
    }

    /** Sends one message synchronously. Returns null on success or an error message. */
    public static function send(string $to, string $subject, string $html, array $attachments = [], ?string $replyTo = null): ?string
    {
        if (!class_exists(PHPMailer::class)) {
            return 'PHPMailer library missing';
        }
        $m = new PHPMailer(true);
        try {
            $m->CharSet = 'UTF-8';
            $driver = (string) Settings::get('mail.driver', 'smtp');
            if ($driver === 'smtp' && Settings::get('mail.smtp_host')) {
                $m->isSMTP();
                $m->Host = (string) Settings::get('mail.smtp_host');
                $m->Port = (int) Settings::get('mail.smtp_port', 587);
                $enc = (string) Settings::get('mail.smtp_encryption', 'tls');
                $m->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($enc === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
                $m->SMTPAutoTLS = $enc !== 'none';
                $user = (string) Settings::get('mail.smtp_username', '');
                if ($user !== '') {
                    $m->SMTPAuth = true;
                    $m->Username = $user;
                    $m->Password = (string) Settings::get('mail.smtp_password', '');
                }
                $m->Timeout = 15;
            } else {
                $m->isMail();
            }
            $from = (string) Settings::get('mail.from_email', '');
            if ($from === '') {
                $host = (string) parse_url((string) App::config('url', ''), PHP_URL_HOST) ?: preg_replace('/:\d+$/', '', App::request()->host());
                $from = 'no-reply@' . (filter_var($host, FILTER_VALIDATE_IP) || !str_contains((string) $host, '.') ? 'localhost.localdomain' : $host);
            }
            $m->setFrom($from, (string) Settings::get('mail.from_name', 'NextaCRM'));
            $m->addAddress($to);
            if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $m->addReplyTo($replyTo);
            }
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body = self::wrap($html);
            $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
            foreach ($attachments as $a) {
                if (!empty($a['content_b64'])) {
                    $m->addStringAttachment((string) base64_decode($a['content_b64']), $a['name'] ?? 'file', PHPMailer::ENCODING_BASE64, $a['mime'] ?? 'application/octet-stream');
                }
            }
            $m->send();
            return null;
        } catch (\Throwable $e) {
            ErrorHandler::log('Mail error: ' . $m->ErrorInfo . ' ' . $e->getMessage(), 'mail');
            return $m->ErrorInfo ?: $e->getMessage();
        }
    }

    public static function wrap(string $inner): string
    {
        $name = e((string) Settings::get('app.name', 'NextaCRM'));
        return '<!doctype html><html><body style="margin:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px">'
            . '<tr><td style="padding:20px 28px;border-bottom:1px solid #f0f0f0;font-weight:600;font-size:15px">' . $name . '</td></tr>'
            . '<tr><td style="padding:24px 28px;font-size:14px;line-height:1.6">' . $inner . '</td></tr>'
            . '</table><p style="font-size:12px;color:#9ca3af;margin-top:16px">Sent by ' . $name . '</p></td></tr></table></body></html>';
    }
}

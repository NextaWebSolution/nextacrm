<?php
declare(strict_types=1);

namespace App\Core\Mail;

defined('NX_ROOT') || exit;

/** Small RFC 5322/2045 parser: headers, text body (plain preferred), attachments. Output is UTF-8. */
final class MimeParser
{
    /** @return array{headers:array, from_email:string, from_name:string, subject:string, message_id:string, in_reply_to:string, text:string, attachments:array} */
    public static function parse(string $raw): array
    {
        [$headers, $body] = self::split($raw);
        $out = ['text' => '', 'html' => '', 'attachments' => []];
        self::walk($headers, $body, $out, 0);
        $text = trim($out['text']) !== '' ? $out['text'] : self::htmlToText($out['html']);
        [$fromName, $fromEmail] = self::address(self::decodeHeader($headers['from'] ?? ''));
        return [
            'headers' => $headers,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'subject' => mb_substr(self::decodeHeader($headers['subject'] ?? ''), 0, 255),
            'message_id' => trim((string) ($headers['message-id'] ?? '')),
            'in_reply_to' => trim((string) ($headers['in-reply-to'] ?? '')),
            'auto' => isset($headers['auto-submitted']) && strtolower(trim($headers['auto-submitted'])) !== 'no' || isset($headers['x-autoreply']) || (isset($headers['precedence']) && preg_match('/bulk|junk|list/i', $headers['precedence'])),
            'text' => self::stripQuoted(trim($text)),
            'attachments' => $out['attachments'],
        ];
    }

    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head) ?? $head; // unfold
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $k = strtolower(trim($k));
                $headers[$k] = isset($headers[$k]) && $k === 'received' ? $headers[$k] : trim($v);
            }
        }
        return [$headers, $body];
    }

    private static function walk(array $h, string $body, array &$out, int $depth): void
    {
        if ($depth > 8) {
            return;
        }
        $ctype = strtolower($h['content-type'] ?? 'text/plain');
        $params = self::params($h['content-type'] ?? '');
        if (str_starts_with($ctype, 'multipart/') && !empty($params['boundary'])) {
            $parts = explode('--' . $params['boundary'], $body);
            array_shift($parts);
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) {
                    break;
                }
                [$ph, $pb] = self::split(ltrim($part, "\r\n"));
                self::walk($ph, $pb, $out, $depth + 1);
            }
            return;
        }
        $data = self::decodeBody($body, strtolower(trim($h['content-transfer-encoding'] ?? '')));
        $disp = strtolower($h['content-disposition'] ?? '');
        $dparams = self::params($h['content-disposition'] ?? '');
        $filename = self::decodeHeader($dparams['filename'] ?? $params['name'] ?? '');
        if (str_starts_with($disp, 'attachment') || ($filename !== '' && !str_starts_with($ctype, 'text/'))) {
            if (count($out['attachments']) < 20 && strlen($data) > 0 && strlen($data) <= 25 * 1048576) {
                $out['attachments'][] = ['name' => $filename !== '' ? $filename : 'attachment', 'data' => $data, 'type' => strtok($ctype, ';')];
            }
            return;
        }
        $charset = $params['charset'] ?? 'UTF-8';
        if (str_starts_with($ctype, 'text/plain') && $out['text'] === '') {
            $out['text'] = self::toUtf8($data, $charset);
        } elseif (str_starts_with($ctype, 'text/html') && $out['html'] === '') {
            $out['html'] = self::toUtf8($data, $charset);
        }
    }

    private static function params(string $header): array
    {
        $p = [];
        if (preg_match_all('/;\s*([a-zA-Z0-9\-*]+)\s*=\s*("([^"]*)"|[^;\s]+)/', $header, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $p[strtolower(rtrim($x[1], '*'))] = $x[3] !== '' ? $x[3] : trim($x[2], '"');
            }
        }
        return $p;
    }

    private static function decodeBody(string $body, string $enc): string
    {
        return match ($enc) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    public static function toUtf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset, "\"' ")) ?: 'UTF-8';
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $conv = @mb_convert_encoding($s, 'UTF-8', $charset);
            $s = is_string($conv) ? $conv : $s;
        }
        return mb_scrub($s, 'UTF-8');
    }

    public static function decodeHeader(string $v): string
    {
        if (!str_contains($v, '=?')) {
            return trim($v);
        }
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return trim(is_string($d) ? $d : mb_decode_mimeheader($v));
    }

    /** @return array{0:string,1:string} name, email */
    public static function address(string $v): array
    {
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>/', $v, $m)) {
            return [trim($m[1]), strtolower(trim($m[2]))];
        }
        $e = strtolower(trim($v, " <>\t"));
        return ['', filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : ''];
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</tr>#i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? '') ?? '');
    }

    /** Removes quoted history from replies ("On … wrote:" and "> " lines). */
    public static function stripQuoted(string $text): string
    {
        $lines = explode("\n", str_replace("\r", '', $text));
        $out = [];
        foreach ($lines as $l) {
            if (preg_match('/^On .{5,200} wrote:\s*$/', trim($l)) || preg_match('/^-{2,}\s*Original Message\s*-{2,}/i', trim($l)) || preg_match('/^From: .+@.+/', trim($l)) && count($out) > 2) {
                break;
            }
            if (str_starts_with(ltrim($l), '>')) {
                continue;
            }
            $out[] = $l;
        }
        return trim(implode("\n", $out)) ?: $text;
    }
}

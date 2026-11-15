<?php
declare(strict_types=1);

namespace App\Core\Mail;

defined('NX_ROOT') || exit;

/**
 * Minimal IMAP4rev1 client over PHP streams (no ext/imap needed — it was removed from PHP 8.4).
 * Supports SSL/TLS (implicit, port 993) and STARTTLS (port 143), LOGIN, SELECT, UID SEARCH, UID FETCH, UID STORE.
 */
final class ImapClient
{
    /** @var resource|null */
    private $s = null;
    private int $tag = 0;

    public function __construct(private string $host, private int $port = 993, private string $encryption = 'ssl', private int $timeout = 20) {}

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $s = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new \RuntimeException("Could not connect to {$this->host}:{$this->port} ($err)");
        }
        stream_set_timeout($s, $this->timeout);
        $this->s = $s;
        $greet = $this->line();
        if (!str_starts_with($greet, '* OK') && !str_starts_with($greet, '* PREAUTH')) {
            throw new \RuntimeException('Unexpected IMAP greeting.');
        }
        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('STARTTLS negotiation failed.');
            }
        }
    }

    public static function quote(string $v): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }

    public function login(string $user, string $pass): void
    {
        if (preg_match('/[\r\n\0]/', $user . $pass)) {
            throw new \RuntimeException('Invalid characters in credentials.');
        }
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
    }

    public function select(string $folder): void
    {
        if (preg_match('/[\r\n\0]/', $folder)) {
            throw new \RuntimeException('Invalid folder name.');
        }
        $this->command('SELECT ' . self::quote($folder));
    }

    /** @return int[] UIDs of unseen messages (oldest first). */
    public function unseen(int $limit = 50): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH UNSEEN')['lines'] as $l) {
            if (str_starts_with($l, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($l, 8))) ?: [] as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        sort($uids);
        return array_slice($uids, 0, $limit);
    }

    /** Raw RFC 822 message (does not set \Seen). */
    public function fetch(int $uid): string
    {
        $r = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        return $r['literals'][0] ?? '';
    }

    public function markSeen(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS (\\Seen)');
    }

    public function logout(): void
    {
        if ($this->s) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
            }
            fclose($this->s);
            $this->s = null;
        }
    }

    /** @return array{lines:string[], literals:string[]} */
    private function command(string $cmd): array
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->s, "$tag $cmd\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->line();
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literals[] = $this->read((int) $m[1]);
                $lines[] = $line;
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!str_starts_with($line, "$tag OK")) {
                    $msg = trim(substr($line, strlen($tag) + 1));
                    throw new \RuntimeException('IMAP: ' . (str_starts_with($cmd, 'LOGIN') ? 'login failed' : mb_substr($msg, 0, 200)));
                }
                return ['lines' => $lines, 'literals' => $literals];
            }
            $lines[] = $line;
        }
    }

    private function line(): string
    {
        $l = fgets($this->s, 65536);
        if ($l === false) {
            throw new \RuntimeException('IMAP connection closed or timed out.');
        }
        return rtrim($l, "\r\n");
    }

    private function read(int $n): string
    {
        if ($n > 30 * 1048576) {
            throw new \RuntimeException('Message too large.');
        }
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->s, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('IMAP connection closed while reading.');
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}

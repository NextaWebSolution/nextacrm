<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * SSRF-safe outbound HTTP client (pattern adapted from Twenty's secure-http-client):
 *  - http/https only, default ports or explicit allow
 *  - DNS is resolved first; private, loopback, link-local, multicast and reserved ranges are refused
 *  - the vetted IP is pinned with CURLOPT_RESOLVE so a second DNS answer cannot change the target
 *  - redirects not followed by request(); requestFollowing() re-checks every hop; short timeouts, response size cap
 */
final class HttpClient
{
    public function __construct(private bool $allowPrivate = false, private int $timeout = 10, private int $maxBytes = 2_097_152) {}

    /** @return array{status:int, body:string, headers:array, error:?string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Only http(s) URLs are allowed.'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Credentials in URLs are not allowed.'];
        }
        $host = trim($parts['host'], '[]');
        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if (!$ips) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Host could not be resolved.'];
        }
        foreach ($ips as $ip) {
            if (!$this->allowPrivate && self::isBlockedIp($ip)) {
                return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Destination address is not allowed (private or reserved network).'];
            }
        }
        $pinned = $ips[0];

        $ch = curl_init();
        $respHeaders = [];
        $buffer = '';
        $max = $this->maxBytes;
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'NextaCRM/' . App::version(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, $max): int {
                if (strlen($buffer) + strlen($chunk) > $max) {
                    return 0; // abort: response too large
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($pinned, ':') ? '[' . $pinned . ']' : $pinned)];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        $ca = (string) App::config('ca_bundle', '');
        if ($ca !== '' && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (curl_error($ch) ?: 'Request failed') : null;
        curl_close($ch);
        return ['status' => $status, 'body' => $buffer, 'headers' => $respHeaders, 'error' => $error];
    }

    /**
     * Like request(), but follows redirects (GitHub release and archive downloads always redirect).
     * Every hop goes through request() again, so each new host is resolved, checked against the
     * private/reserved IP block list and pinned. HTTPS → HTTP downgrades are refused.
     */
    public function requestFollowing(string $method, string $url, array $headers = [], int $maxHops = 5): array
    {
        for ($hop = 0; $hop <= $maxHops; $hop++) {
            $res = $this->request($method, $url, $headers);
            $loc = $res['headers']['location'] ?? '';
            if ($res['error'] !== null || !in_array($res['status'], [301, 302, 303, 307, 308], true) || $loc === '') {
                return $res + ['final_url' => $url];
            }
            $next = self::absoluteUrl($url, $loc);
            if ($next === null) {
                return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Invalid redirect.', 'final_url' => $url];
            }
            if (str_starts_with(strtolower($url), 'https://') && !str_starts_with(strtolower($next), 'https://')) {
                return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Refused a redirect from HTTPS to HTTP.', 'final_url' => $url];
            }
            if ($res['status'] === 303) {
                $method = 'GET';
            }
            $url = $next;
        }
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Too many redirects.', 'final_url' => $url];
    }

    /** Resolves a Location header against the URL that returned it. */
    public static function absoluteUrl(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x1f\s]/', $location)) {
            return null;
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(substr((string) ($b['path'] ?? '/'), 0, (int) strrpos((string) ($b['path'] ?? '/'), '/') + 1), '/');
        return $origin . $dir . '/' . $location;
    }

    private function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if (!$ips) {
            $v4 = @gethostbynamel($host) ?: [];
            $ips = $v4;
        }
        return array_values(array_unique($ips));
    }

    public static function isBlockedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        // Built-in filters cover RFC1918, loopback (v4), and reserved ranges.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        $blockedCidrs = [
            '0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::1/128', '::/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '::ffff:0:0/96', '64:ff9b::/96', '2001:db8::/32',
        ];
        foreach ($blockedCidrs as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }
}

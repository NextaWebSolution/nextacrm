<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/** Fixed-window rate limiter stored in the database (works on shared hosting without Redis). */
final class RateLimiter
{
    /** Returns true when the action is allowed, false when the limit was exceeded. */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $db = App::db();
        $k = substr(hash('sha256', $key), 0, 64);
        $now = time();
        $row = $db->one('SELECT hits, reset_at FROM rate_limits WHERE k = ?', [$k]);
        if (!$row || (int) $row['reset_at'] <= $now) {
            if ($row) {
                $db->update('rate_limits', ['hits' => 1, 'reset_at' => $now + $windowSeconds], ['k' => $k]);
            } else {
                try {
                    $db->insert('rate_limits', ['k' => $k, 'hits' => 1, 'reset_at' => $now + $windowSeconds]);
                } catch (\PDOException) {
                    $db->query('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', [$k]);
                }
            }
            return true;
        }
        $db->query('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', [$k]);
        return (int) $row['hits'] + 1 <= $max;
    }

    /** True while the key is still under $max hits in its current window (does not count a hit). */
    public static function check(string $key, int $max): bool
    {
        $row = App::db()->one('SELECT hits, reset_at FROM rate_limits WHERE k = ?', [substr(hash('sha256', $key), 0, 64)]);
        return !$row || (int) $row['reset_at'] <= time() || (int) $row['hits'] < $max;
    }

    public static function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        return !self::hit($key, $max, $windowSeconds);
    }

    public static function clear(string $key): void
    {
        App::db()->delete('rate_limits', ['k' => substr(hash('sha256', $key), 0, 64)]);
    }

    public static function retryAfter(string $key): int
    {
        $v = App::db()->value('SELECT reset_at FROM rate_limits WHERE k = ?', [substr(hash('sha256', $key), 0, 64)]);
        return max(1, (int) $v - time());
    }
}

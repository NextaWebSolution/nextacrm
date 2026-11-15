<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Symmetric encryption for secrets at rest (libsodium secretbox, XSalsa20-Poly1305).
 */
final class Crypto
{
    private static function key(): string
    {
        $k = (string) App::config('app_key', '');
        $raw = base64_decode($k, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Application key is missing or invalid.');
        }
        return $raw;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!str_starts_with($value, 'enc:')) {
            return $value; // plain (legacy) value
        }
        $raw = base64_decode(substr($value, 4), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16) {
            return '';
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? '' : $plain;
    }

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1])
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** HMAC signature for public links (invoice view, quote accept). */
    public static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }

    public static function verifySignature(string $data, string $sig): bool
    {
        return hash_equals(self::sign($data), $sig);
    }
}

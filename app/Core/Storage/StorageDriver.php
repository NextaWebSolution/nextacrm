<?php
declare(strict_types=1);

namespace App\Core\Storage;

defined('NX_ROOT') || exit;

interface StorageDriver
{
    /** Store a local file under $key. */
    public function putFile(string $key, string $localPath, string $mime): void;

    /** @return resource A readable stream positioned at 0. */
    public function readStream(string $key);

    public function delete(string $key): void;

    public function exists(string $key): bool;

    /** Optional direct download URL (pre-signed). Return null when not supported. */
    public function temporaryUrl(string $key, int $ttl, string $filename, string $mime): ?string;

    /** Round-trip test used by the admin "Test connection" button. Returns [ok, message]. */
    public function test(): array;
}

<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Response;
use App\Core\Storage\StorageManager;

final class FileService
{
    /** Never accepted, regardless of the configured allow-list. */
    public const BLOCKED_EXT = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'dll', 'vbs', 'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'htaccess', 'htpasswd', 'ini', 'user.ini', 'hta', 'jar', 'swf', 'wasm'];
    public const DANGEROUS_MIME = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/x-httpd-php', 'text/x-php', 'application/x-php', 'application/javascript', 'text/javascript', 'application/x-msdownload', 'application/x-sh', 'application/x-executable', 'application/java-archive'];
    public const INLINE_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    /**
     * Validate and store an uploaded file.
     * @param array $upload  An entry from $_FILES
     */
    /**
     * Stores a file the server itself wrote under storage/tmp (e.g. an e-mail attachment). All type and content
     * checks of upload() still apply; only the "came from an HTTP upload" check is replaced by the path check.
     */
    public static function storeLocal(string $tmpPath, string $name, ?string $entity = null, ?int $recordId = null): array
    {
        $real = realpath($tmpPath) ?: '';
        $base = realpath(NX_ROOT . '/storage/tmp') ?: "\0";
        if ($real === '' || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new HttpException(422, 'Invalid local file.');
        }
        self::$localTrusted = true;
        try {
            return self::upload(['name' => $name, 'tmp_name' => $real, 'error' => UPLOAD_ERR_OK, 'size' => filesize($real)], $entity, $recordId);
        } finally {
            self::$localTrusted = false;
        }
    }

    private static bool $localTrusted = false;

    public static function upload(array $upload, ?string $entity = null, ?int $recordId = null, ?int $folderId = null, bool $imageOnly = false): array
    {
        $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new HttpException(422, match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
                UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
                default => 'The upload failed. Please try again.',
            });
        }
        $tmp = (string) $upload['tmp_name'];
        if (!is_uploaded_file($tmp) && !self::$localTrusted && !defined('NX_TESTING')) {
            throw new HttpException(422, 'Invalid upload.');
        }
        $size = (int) filesize($tmp);
        $maxMb = max(1, (int) setting('security.max_upload_mb', 25));
        if ($size <= 0 || $size > $maxMb * 1048576) {
            throw new HttpException(422, "Files must be between 1 byte and {$maxMb} MB.");
        }
        $original = self::cleanName((string) ($upload['name'] ?? 'file'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = array_filter(array_map('trim', explode(',', strtolower((string) setting('security.allowed_upload_ext', '')))));
        if ($imageOnly) {
            $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        }
        // Reject double extensions like invoice.php.pdf as well as blocked types.
        $allExts = array_slice(explode('.', strtolower($original)), 1);
        if ($ext === '' || in_array($ext, self::BLOCKED_EXT, true) || array_intersect($allExts, ['php', 'phtml', 'phar', 'cgi', 'asp', 'aspx', 'jsp']) || !in_array($ext, $allowed, true)) {
            throw new HttpException(422, 'This file type is not allowed.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (in_array($mime, self::DANGEROUS_MIME, true) || str_contains($mime, 'php')) {
            throw new HttpException(422, 'This file content is not allowed.');
        }
        $head = (string) file_get_contents($tmp, false, null, 0, 1024);
        if (preg_match('/<\?php|<\?=|<script\b/i', $head) && !in_array($ext, ['txt', 'csv', 'json'], true)) {
            throw new HttpException(422, 'This file content is not allowed.');
        }
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            if (!str_starts_with($mime, 'image/') || @getimagesize($tmp) === false) {
                throw new HttpException(422, 'The image appears to be corrupted.');
            }
        }

        $cid = Auth::companyId();
        $key = $cid . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $driverName = StorageManager::defaultDriver();
        try {
            StorageManager::disk($driverName)->putFile($key, $tmp, $mime);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::log('Storage upload failed: ' . $e->getMessage(), 'storage');
            throw new HttpException(500, 'The file could not be stored. An administrator should check the storage settings.');
        }
        $id = App::db()->insert('files', [
            'company_id' => $cid, 'folder_id' => $folderId ?: null, 'name' => $original, 'path' => $key, 'driver' => $driverName,
            'mime' => $mime, 'ext' => $ext, 'size' => $size, 'checksum' => hash_file('sha256', $tmp),
            'entity' => $entity, 'record_id' => $recordId, 'uploaded_by' => Auth::id() ?: null, 'is_public' => 0, 'created_at' => DB::now(),
        ]);
        Audit::log('file.uploaded', $entity, $recordId, ['file' => $original, 'size' => $size]);
        if ($entity && $recordId) {
            ActivityService::log($entity, $recordId, 'system', 'Attached file: ' . $original, null, ['file_id' => $id]);
        }
        return App::db()->find('files', $id);
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? 'file';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? 'file');
        return mb_substr($name !== '' ? $name : 'file', 0, 180);
    }

    /** Load a file row the current user may access. */
    public static function get(int $id): array
    {
        $f = App::db()->one('SELECT * FROM files WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [$id, Auth::companyId()]);
        if (!$f) {
            throw new HttpException(404);
        }
        self::authorize($f, 'view');
        return $f;
    }

    /** Access follows the parent record; library files follow the Files permission. */
    public static function authorize(array $f, string $action): void
    {
        if ($f['entity'] && EntityRegistry::exists($f['entity'])) {
            $svc = RecordService::for($f['entity']);
            $svc->find((int) $f['record_id'], $action === 'view' ? 'view' : 'edit');
            return;
        }
        if ($f['entity'] === 'companies' || $f['entity'] === 'users') {
            return; // logos and avatars
        }
        $level = Permissions::level('files', $action === 'view' ? 'view' : ($action === 'delete' ? 'delete' : 'edit'));
        if ($level === 'none' || ($level === 'own' && (int) $f['uploaded_by'] !== Auth::id())) {
            throw new HttpException(403);
        }
    }

    public static function download(int $id, bool $inline = false): Response
    {
        return self::stream(self::get($id), $inline);
    }

    /** Streams a file row the caller has already authorised. */
    public static function stream(array $f, bool $inline = false): Response
    {
        $id = (int) $f['id'];
        $inline = $inline && in_array($f['mime'], self::INLINE_MIME, true);
        $disk = StorageManager::disk($f['driver']);
        if (!$inline && setting('storage.signed_urls', false)) {
            $url = $disk->temporaryUrl($f['path'], 300, $f['name'], (string) $f['mime']);
            if ($url) {
                $r = new Response('', 302);
                $r->headers['Location'] = $url;
                return $r;
            }
        }
        try {
            $stream = $disk->readStream($f['path']);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::log('Storage read failed for file ' . $id . ': ' . $e->getMessage(), 'storage');
            throw new HttpException(404, 'The file could not be retrieved from storage.');
        }
        $mime = in_array($f['mime'], self::DANGEROUS_MIME, true) ? 'application/octet-stream' : (string) ($f['mime'] ?: 'application/octet-stream');
        $resp = Response::stream($stream, $f['name'], $mime, null, $inline);
        $resp->headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";
        return $resp;
    }

    public static function delete(int $id): void
    {
        $f = App::db()->one('SELECT * FROM files WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [$id, Auth::companyId()]);
        if (!$f) {
            throw new HttpException(404);
        }
        self::authorize($f, 'delete');
        App::db()->update('files', ['deleted_at' => DB::now()], ['id' => $id]);
        try {
            StorageManager::disk($f['driver'])->delete($f['path']);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::log('Storage delete failed: ' . $e->getMessage(), 'storage');
        }
        Audit::log('file.deleted', $f['entity'], $f['record_id'] ? (int) $f['record_id'] : null, ['file' => $f['name']]);
    }

    public static function forRecord(string $entity, int $recordId): array
    {
        $rows = App::db()->all('SELECT * FROM files WHERE company_id = ? AND entity = ? AND record_id = ? AND deleted_at IS NULL ORDER BY id DESC', [Auth::companyId(), $entity, $recordId]);
        $users = LookupService::users(array_column($rows, 'uploaded_by'));
        foreach ($rows as &$r) {
            $r['uploader'] = $users[(int) $r['uploaded_by']]['name'] ?? null;
        }
        return $rows;
    }

    /** Embed an image (company logo) as a data URI for PDFs. */
    public static function dataUri(int $id): ?string
    {
        $f = App::db()->find('files', $id);
        if (!$f || !in_array($f['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return null;
        }
        try {
            $s = StorageManager::disk($f['driver'])->readStream($f['path']);
            $data = stream_get_contents($s);
            fclose($s);
            return 'data:' . $f['mime'] . ';base64,' . base64_encode((string) $data);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function humanSize(int $bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 3) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : number_format($n, 1)) . ' ' . $u[$i];
    }

    public static function iconFor(?string $ext): string
    {
        return match (strtolower((string) $ext)) {
            'png', 'jpg', 'jpeg', 'gif', 'webp' => 'image',
            'xls', 'xlsx', 'csv', 'ods' => 'file-spreadsheet',
            'zip', 'rar', '7z' => 'archive',
            default => 'file-text',
        };
    }
}

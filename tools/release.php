<?php
/**
 * Builds a signed release package for the auto-updater.
 *
 *   NEXTACRM_SIGNING_KEY=<base64 Ed25519 secret key> php tools/release.php [--key-file=/path/signing.secret] [--out=dist]
 *
 * Produces:
 *   dist/nextacrm-<version>.zip   installable package (also used for fresh cPanel installs)
 *   dist/latest.json              upload to <update.server>/latest.json; set "url" to where the zip is hosted
 *
 * The secret key must NEVER be stored inside the application or committed to the repository.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__);
$opts = getopt('', ['key-file:', 'out:', 'url:', 'notes:']);
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$version = (string) $manifest['version'];
$outOpt = (string) ($opts['out'] ?? 'dist');
$out = rtrim(str_starts_with($outOpt, '/') ? $outOpt : $root . '/' . $outOpt, '/');

$keyB64 = getenv('NEXTACRM_SIGNING_KEY') ?: (isset($opts['key-file']) ? trim((string) file_get_contents((string) $opts['key-file'])) : '');
$sk = base64_decode($keyB64, true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Signing key missing or invalid. Set NEXTACRM_SIGNING_KEY or pass --key-file.\n");
    exit(1);
}
$pub = base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk));
if ($pub !== ($manifest['update']['public_key'] ?? '')) {
    fwrite(STDERR, "This key does not match update.public_key in manifest.json — installs would reject the update.\n");
    exit(1);
}

// Never package local configuration, data, or dev artefacts.
$exclude = [
    '#^config/config\.php$#', '#^storage/(?!\.htaccess$|[a-z]+/index\.html$|[a-z]+/?$).+#',
    '#^dist/#', '#^node_modules/#', '#^\.git/#', '#\.sqlite$#', '#(^|/)\.DS_Store$#', '#^tools/.*\.secret$#', '#signing\.secret$#', '#(^|/)\.env$#', '#(^|/)\.git/#', '#^\.github/workflows/#', '#^tools/smoke-install\.php$#', '#^\.github(/|$)#', '#^modules/(?!\.htaccess$).+#', '#^tests(/|$)#', '#^examples(/|$)#',
];
@mkdir($out, 0755, true);
$zipPath = "$out/nextacrm-$version.zip";
@unlink($zipPath);
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$count = 0;
foreach ($it as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($file->getPathname() . '/', $out . '/')) { continue; }
    foreach ($exclude as $rx) {
        if (preg_match($rx, $rel)) { continue 2; }
    }
    if ($file->isDir()) {
        $zip->addEmptyDir("nextacrm/$rel");
    } else {
        $zip->addFile($file->getPathname(), "nextacrm/$rel");
        $count++;
    }
}
$zip->close();
$sha = hash_file('sha256', $zipPath);
$sig = base64_encode(sodium_crypto_sign_detached("nextacrm|$version|$sha", $sk));
sodium_memzero($sk);
$latest = [
    'version' => $version,
    'released_at' => $manifest['release_date'] ?? gmdate('Y-m-d'),
    'url' => (string) ($opts['url'] ?? rtrim((string) ($manifest['update']['server'] ?? ''), '/') . "/nextacrm-$version.zip"),
    'sha256' => $sha,
    'signature' => $sig,
    'requires_php' => $manifest['requirements']['php'] ?? '8.2.0',
    'notes' => (string) ($opts['notes'] ?? ''),
];
file_put_contents("$out/latest.json", json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf("Built %s (%d files, %.1f MB)\nsha256 %s\nWrote %s/latest.json\n", $zipPath, $count, filesize($zipPath) / 1048576, $sha, $out);

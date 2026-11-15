<?php
/**
 * Packages a module folder into a ZIP and (optionally) signs it.
 *
 *   php tools/build-module.php examples/modules/birthday-reminders [--key-file=/secure/signing.secret] [--out=dist/modules] [--url-base=https://…]
 *
 * Outputs <slug>-<version>.zip, <slug>-<version>.sig and a catalog entry (<slug>.json) for the marketplace feed.
 * The signing secret never leaves your machine; do not commit it.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$src = rtrim((string) ($argv[1] ?? ''), '/');
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
if ($src === '' || !is_file("$src/module.json") || !is_file("$src/module.php")) {
    fwrite(STDERR, "Usage: php tools/build-module.php <module-folder> [--key-file=…] [--out=dist/modules] [--url-base=https://…]\n");
    exit(1);
}
$m = json_decode((string) file_get_contents("$src/module.json"), true);
if (!is_array($m) || !preg_match('/^[a-z0-9][a-z0-9\-]{1,39}$/', (string) ($m['slug'] ?? '')) || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['version'] ?? ''))) {
    fwrite(STDERR, "module.json needs a valid slug and version.\n");
    exit(1);
}
$out = rtrim($opts['out'] ?? 'dist/modules', '/');
@mkdir($out, 0755, true);
$base = $m['slug'] . '-' . $m['version'];
$zipPath = "$out/$base.zip";
@unlink($zipPath);
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($src) + 1);
    if (str_starts_with(basename($rel), '.') && basename($rel) !== '.htaccess') {
        continue;
    }
    $files[$rel] = $f->getPathname();
}
ksort($files);
foreach ($files as $rel => $path) {
    $zip->addFile($path, $rel);
    $zip->setMtimeName($rel, 1700000000); // reproducible builds
}
$zip->close();
$sha = hash_file('sha256', $zipPath);
$sig = '';
if (!empty($opts['key-file'])) {
    $secret = base64_decode(trim((string) file_get_contents($opts['key-file'])), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "Invalid key file.\n");
        exit(1);
    }
    $sig = base64_encode(sodium_crypto_sign_detached('nextacrm-module|' . $m['slug'] . '|' . $m['version'] . '|' . $sha, $secret));
    file_put_contents("$out/$base.sig", $sig . "\n");
}
$entry = [
    'slug' => $m['slug'], 'name' => $m['name'] ?? $m['slug'], 'version' => $m['version'], 'description' => $m['description'] ?? '',
    'requires' => $m['requires'] ?? new stdClass(), 'download_url' => rtrim($opts['url-base'] ?? 'https://updates.nextawebsolution.com/nextacrm/modules', '/') . "/$base.zip",
    'sha256' => $sha, 'signature' => $sig,
];
file_put_contents("$out/{$m['slug']}.json", json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Built $zipPath\nsha256 $sha\n" . ($sig ? "signature $out/$base.sig\n" : "UNSIGNED (pass --key-file to sign)\n");

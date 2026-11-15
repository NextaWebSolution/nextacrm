<?php
// Loads every class under app/ so signature/compatibility fatals surface even on untested routes.
define('NX_ROOT', $argv[1] ?? '/home/claude/nextacrm');
require NX_ROOT . '/app/bootstrap.php';
$n = 0; $bad = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(NX_ROOT . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php') || str_contains($p, '/Views/') || str_contains($p, '/Entities/') || str_contains($p, '/migrations/') || preg_match('#app/(features|routes|bootstrap|Core/icons|Core/helpers)\.php$#', $p) || str_ends_with($p, 'schema.php')) continue;
    $src = file_get_contents($p);
    if (!preg_match('/namespace\s+([^;]+);/', $src, $ns) || !preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $src, $cl)) continue;
    $fq = $ns[1] . '\\' . $cl[1];
    $cmd = 'php -r ' . escapeshellarg('define("NX_ROOT",' . var_export(NX_ROOT, true) . '); require NX_ROOT."/app/bootstrap.php"; class_exists(' . var_export($fq, true) . ') || interface_exists(' . var_export($fq, true) . ') || trait_exists(' . var_export($fq, true) . ') || exit(3);') . ' 2>&1';
    $out = shell_exec($cmd);
    $n++;
    if (trim((string) $out) !== '') { $bad++; echo "$fq: " . trim($out) . "\n"; }
}
echo "$n classes loaded, $bad problems\n";

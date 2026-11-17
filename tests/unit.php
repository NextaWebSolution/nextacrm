<?php
define('NX_ROOT', '/home/claude/nextacrm');
require NX_ROOT . '/app/bootstrap.php';
$ok = 0; $fail = 0;
$t = function (string $name, bool $cond) use (&$ok, &$fail) { echo ($cond ? 'OK   ' : 'FAIL ') . $name . "\n"; $cond ? $ok++ : $fail++; };

// AWS SigV4 official example (S3 GET Object with Range header)
$s3 = new App\Core\Storage\S3Driver(['key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'region' => 'us-east-1', 'bucket' => 'examplebucket']);
$h = $s3->debugSign('GET', '/test.txt', 'examplebucket.s3.amazonaws.com', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', ['range' => 'bytes=0-9'], '20130524T000000Z');
$auth = implode("\n", $h);
$t('SigV4 matches AWS reference signature', str_contains($auth, 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41'));
$t('SigV4 signed headers list', str_contains($auth, 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date'));

// TOTP RFC 6238 test vector (SHA1, secret "12345678901234567890", T=59 -> 94287082 → 6 digits 287082)
$secret = App\Core\Totp::base32Encode('12345678901234567890');
$t('TOTP RFC6238 vector', App\Core\Totp::code($secret, 59) === '287082');
$t('TOTP vector 1111111109', App\Core\Totp::code($secret, 1111111109) === '081804');
$t('Base32 round trip', App\Core\Totp::base32Decode(App\Core\Totp::base32Encode("\x00\xffhello")) === "\x00\xffhello");

// SSRF guard
foreach (['127.0.0.1', '10.1.2.3', '192.168.1.1', '172.16.5.4', '169.254.169.254', '::1', 'fc00::1', 'fe80::1', '100.64.0.1', '0.0.0.0', '::ffff:127.0.0.1'] as $ip) {
    $t("blocks $ip", App\Core\HttpClient::isBlockedIp($ip));
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $ip) {
    $t("allows $ip", !App\Core\HttpClient::isBlockedIp($ip));
}
$r = (new App\Core\HttpClient())->request('GET', 'http://127.0.0.1:8099/login');
$t('HttpClient refuses localhost', ($r['error'] ?? '') !== '' && $r['status'] === 0);
$r = (new App\Core\HttpClient())->request('GET', 'file:///etc/passwd');
$t('HttpClient refuses file://', $r['status'] === 0);
$r = (new App\Core\HttpClient())->request('GET', 'http://user:pass@example.com/');
$t('HttpClient refuses credentials in URL', $r['status'] === 0);

// Storage key safety
foreach (['../etc/passwd', '/abs/path', 'a/../b', "a\0b", 'a\\b', '.htaccess', 'a/./b'] as $bad) {
    $threw = false; try { App\Core\Storage\StorageManager::assertSafeKey($bad); } catch (\InvalidArgumentException) { $threw = true; }
    $t('rejects storage key ' . json_encode($bad), $threw);
}
$t('accepts normal key', (function () { App\Core\Storage\StorageManager::assertSafeKey('1/2026/11/abc123.pdf'); return true; })());

// Response redirect safety
$t('open redirect blocked //evil', !App\Core\Response::isSafePath('//evil.com'));
$t('open redirect blocked /\\evil', !App\Core\Response::isSafePath('/\\evil.com'));
$t('open redirect blocked http', !App\Core\Response::isSafePath('https://evil.com'));
$t('safe path ok', App\Core\Response::isSafePath('/r/leads?x=1'));

// Duration parsing
$t('duration 1:30', App\Services\RecordService::parseDuration('1:30') === 90);
$t('duration 1.5h', App\Services\RecordService::parseDuration('1.5h') === 90);
$t('duration 45m', App\Services\RecordService::parseDuration('45m') === 45);
$t('duration 2h15m', App\Services\RecordService::parseDuration('2h15m') === 135);
$t('duration garbage', App\Services\RecordService::parseDuration('abc') === null);

// Totals
$items = [['line_total' => 1000, 'tax_amount' => 180], ['line_total' => 500, 'tax_amount' => 0]];
$tot = App\Services\SalesService::computeTotals($items, 'percent', 10, 0);
$t('totals subtotal', $tot['subtotal'] == 1500.0);
$t('totals discount', $tot['discount_total'] == 150.0);
$t('totals tax proportional', abs($tot['tax_total'] - 162.0) < 0.001);
$t('totals total', abs($tot['total'] - 1512.0) < 0.001);
$t('indian format', indian_format(12345678.5) === '1,23,45,678.50');

// CSV formula injection guard
$csv = App\Core\Xlsx::csv(['a'], [['=HYPERLINK("x")'], ['-5'], ['@cmd']]);
$t('CSV formula injection neutralised', str_contains($csv, "'=HYPERLINK") && str_contains($csv, "'@cmd") && str_contains($csv, "\n-5"));
// XLSX valid zip
$x = App\Core\Xlsx::build(['Name', 'Amount'], [['A & B <x>', 10.5]]);
file_put_contents('/tmp/t.xlsx', $x); $z = new ZipArchive(); $t('XLSX opens as zip', $z->open('/tmp/t.xlsx') === true && $z->locateName('xl/worksheets/sheet1.xml') !== false);

// Signature verification of updates
$sk = base64_decode(trim(file_get_contents('/home/claude/signing.secret')));
$sha = hash('sha256', 'pkg');
$sig = base64_encode(sodium_crypto_sign_detached('nextacrm|1.0.1|' . $sha, $sk));
$t('update signature valid', App\Services\UpdateService::verifySignature('1.0.1', $sha, $sig));
$t('update signature rejects tampered version', !App\Services\UpdateService::verifySignature('1.0.2', $sha, $sig));
$t('update signature rejects tampered hash', !App\Services\UpdateService::verifySignature('1.0.1', hash('sha256', 'evil'), $sig));

// Schema compiles for MySQL
$sql = App\Core\Schema::load()->compile('mysql');
$t('MySQL DDL statements generated (' . count($sql) . ')', count($sql) > 100);
$t('MySQL DDL uses InnoDB utf8mb4', str_contains($sql[0], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
$long = array_filter($sql, fn ($s) => preg_match('/INDEX `([^`]{61,})`/', $s));
$t('index names within 64 chars', !$long);

// ── v2.0 pure logic ──
use App\Services\PayrollService as PR;
use App\Services\Payments\Gateway as GW;
$cfg = ['basic_pct' => 50, 'hra_pct' => 40, 'pf_rate' => 12, 'pf_cap' => 15000, 'esi_rate' => 0.75, 'esi_threshold' => 21000, 'pt' => 200, 'basis' => 'calendar'];
$full = PR::compute(['salary' => 60000, 'pf_applicable' => 1, 'esi_applicable' => 0, 'pt_applicable' => 1], ['working' => 26, 'employed' => 30, 'lop' => 0], 30, $cfg);
$t('payroll: full month gross = salary', $full['gross'] == 60000.0);
$t('payroll: basic/HRA/special split adds up', abs($full['basic'] + $full['hra'] + $full['special'] - $full['gross']) < 0.01);
$t('payroll: PF capped at 12% of 15,000', $full['pf'] == 1800.0);
$t('payroll: net = gross - PF - PT', $full['net'] == 60000 - 1800 - 200);
$lop = PR::compute(['salary' => 30000, 'pf_applicable' => 0, 'esi_applicable' => 0, 'pt_applicable' => 0], ['working' => 26, 'employed' => 30, 'lop' => 3], 30, $cfg);
$t('payroll: 3 LOP days in a 30-day month pay 27/30', $lop['gross'] == 27000.0 && $lop['paid_days'] == 27.0);
$esi = PR::compute(['salary' => 18000, 'pf_applicable' => 1, 'esi_applicable' => 1, 'pt_applicable' => 1], ['working' => 26, 'employed' => 30, 'lop' => 0], 30, $cfg);
$t('payroll: ESI 0.75% rounded up for eligible wage', $esi['esi'] == 135.0);
$noEsi = PR::compute(['salary' => 25000, 'pf_applicable' => 0, 'esi_applicable' => 1, 'pt_applicable' => 0], ['working' => 26, 'employed' => 30, 'lop' => 0], 30, $cfg);
$t('payroll: no ESI above the wage threshold', $noEsi['esi'] == 0.0);
$join = PR::compute(['salary' => 31000, 'pf_applicable' => 0, 'esi_applicable' => 0, 'pt_applicable' => 0], ['working' => 10, 'employed' => 10, 'lop' => 0], 31, $cfg);
$t('payroll: mid-month joiner paid for days employed', $join['gross'] == 10000.0);
$neg = PR::compute(['salary' => 10000, 'pf_applicable' => 0, 'esi_applicable' => 0, 'pt_applicable' => 0], ['working' => 26, 'employed' => 30, 'lop' => 0], 30, $cfg, 50000);
$t('payroll: net never negative', $neg['net'] == 0.0);
$t('payroll: LOP never exceeds days employed', PR::compute(['salary' => 30000], ['working' => 5, 'employed' => 5, 'lop' => 9], 30, $cfg)['gross'] == 0.0);
$t('currency: INR uses paise', GW::minor(5000.5, 'INR') === 500050);
$t('currency: JPY is zero-decimal', GW::minor(5000, 'JPY') === 5000 && GW::fromMinor(5000, 'jpy') == 5000.0);
$t('currency: KWD is three-decimal', GW::minor(1.234, 'KWD') === 1234);
$t('currency: float noise does not lose a cent', GW::minor(19.99, 'USD') === 1999 && GW::minor(0.29, 'USD') === 29);
$md = markdown_safe("# T\n<script>alert(1)</script> [x](javascript:alert(1)) **b** `<i>`");
$t('markdown_safe: no raw script, no javascript: links', !str_contains($md, '<script') && !preg_match('/href="\s*javascript/i', $md));
$t('markdown_safe: formatting kept', str_contains($md, '<strong>b</strong>') && str_contains($md, '<h'));

// ── 2.0.2: redirect handling for GitHub downloads ──
use App\Core\HttpClient as HC;
$t('redirect: absolute https kept', HC::absoluteUrl('https://github.com/a/b', 'https://objects.githubusercontent.com/x?y=1') === 'https://objects.githubusercontent.com/x?y=1');
$t('redirect: root-relative resolved', HC::absoluteUrl('https://github.com/a/b/c.zip', '/d/e.zip') === 'https://github.com/d/e.zip');
$t('redirect: relative resolved', HC::absoluteUrl('https://h.test/a/b/c.zip', 'd.zip') === 'https://h.test/a/b/d.zip');
$t('redirect: protocol-relative keeps scheme', HC::absoluteUrl('https://h.test/a', '//cdn.test/x') === 'https://cdn.test/x');
$t('redirect: header injection refused', HC::absoluteUrl('https://h.test/a', "https://x.test/\r\nSet-Cookie: a=b") === null);
$t('redirect: private target still blocked', HC::isBlockedIp('127.0.0.1') && HC::isBlockedIp('169.254.169.254') && HC::isBlockedIp('10.0.0.5'));
echo "\n$ok passed, $fail failed\n";

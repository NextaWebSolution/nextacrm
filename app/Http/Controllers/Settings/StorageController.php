<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Storage\StorageManager;
use App\Http\Controllers\Controller;

final class StorageController extends Controller
{
    public function index(Request $r): Response
    {
        $config = [];
        foreach (array_keys(StorageManager::DRIVERS) as $d) {
            $cfg = StorageManager::config($d);
            foreach (StorageManager::secretFields() as $s) {
                if (isset($cfg[$s])) {
                    $cfg[$s] = $cfg[$s] !== '' ? '__keep__' : '';
                }
            }
            $config[$d] = $cfg;
        }
        $usage = App::db()->all('SELECT driver, COUNT(*) AS c, COALESCE(SUM(size),0) AS s FROM files WHERE deleted_at IS NULL GROUP BY driver');
        return $this->view('settings/storage', ['title' => 'File storage', 'tab' => 'storage', 'config' => $config, 'active' => StorageManager::defaultDriver(), 'usage' => $usage]);
    }

    private function collect(Request $r, string $driver): array
    {
        $in = (array) ($r->input('cfg', [])[$driver] ?? []);
        $out = [];
        foreach (array_keys(StorageManager::fields($driver)) as $k) {
            $v = is_string($in[$k] ?? null) ? trim($in[$k]) : (isset($in[$k]) ? '1' : '');
            if (in_array($k, StorageManager::secretFields(), true) && ($v === '__keep__' || $v === '')) {
                $v = (string) Settings::get("storage.$driver.$k", '');
            }
            if ($k === 'endpoint' && $v !== '' && !preg_match('#^https?://#i', $v)) {
                $v = 'https://' . $v;
            }
            $out[$k] = mb_substr($v, 0, $k === 'private_key' ? 10000 : 500);
        }
        return $out;
    }

    public function save(Request $r): Response
    {
        $driver = $r->str('driver');
        if (!array_key_exists($driver, StorageManager::DRIVERS)) {
            throw new HttpException(422, 'Unknown driver.');
        }
        $cfg = $this->collect($r, $driver);
        if ($driver !== 'local' && $r->input('skip_test') !== '1') {
            [$ok, $msg] = StorageManager::disk($driver, $cfg)->test();
            if (!$ok) {
                return $this->back('error', 'Connection test failed, settings were not saved: ' . $msg);
            }
        }
        foreach ($cfg as $k => $v) {
            Settings::set("storage.$driver.$k", $v);
        }
        Settings::set('storage.driver', $driver);
        Settings::set('storage.signed_urls', (bool) $r->input('signed_urls'));
        StorageManager::reset();
        Audit::log('settings.storage', null, null, ['driver' => $driver]);
        return $this->back('success', 'Storage settings saved. New uploads go to ' . StorageManager::DRIVERS[$driver] . '. Existing files stay where they are.');
    }

    public function test(Request $r): Response
    {
        $driver = $r->str('driver');
        if (!array_key_exists($driver, StorageManager::DRIVERS)) {
            throw new HttpException(422, 'Unknown driver.');
        }
        [$ok, $msg] = StorageManager::disk($driver, $this->collect($r, $driver))->test();
        return $this->json(['ok' => $ok, 'message' => $msg]);
    }
}

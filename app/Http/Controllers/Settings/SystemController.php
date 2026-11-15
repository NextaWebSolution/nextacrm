<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Http\Controllers\Controller;
use App\Services\BackupService;
use App\Services\CronService;
use App\Services\EntityRegistry;
use App\Services\InstallService;
use App\Services\UpdateService;

final class SystemController extends Controller
{
    public function index(Request $r): Response
    {
        $db = App::db();
        $modules = [];
        foreach (EntityRegistry::all() as $d) {
            $modules[$d['module']] = $d['module_label'] ?? $d['label'];
        }
        $last = Settings::get('update.last_check');
        return $this->view('settings/system', [
            'title' => 'System', 'tab' => 'system',
            'checks' => InstallService::requirements(),
            'dbVersion' => $db->driver === 'mysql' ? (string) $db->value('SELECT VERSION()') : 'SQLite ' . $db->value('SELECT sqlite_version()'),
            'cronLast' => (int) Settings::get('cron.last_run', 0),
            'cronKey' => (string) Settings::get('cron.key', ''),
            'backups' => BackupService::list(),
            'modules' => $modules, 'disabled' => (array) setting('app.modules_disabled', []),
            'lastCheck' => $last, 'pendingEmails' => (int) $db->value("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'"),
            'failedEmails' => (int) $db->value("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'"),
        ]);
    }

    public function modules(Request $r): Response
    {
        $all = [];
        foreach (EntityRegistry::all() as $d) {
            $all[] = $d['module'];
        }
        $enabled = (array) $r->input('enabled', []);
        $disabled = array_values(array_diff(array_unique($all), $enabled));
        Settings::set('app.modules_disabled', $disabled);
        Audit::log('settings.modules', null, null, ['disabled' => $disabled]);
        return $this->back('success', 'Modules updated.');
    }

    public function backup(Request $r): Response
    {
        $name = BackupService::create('manual', (bool) $r->input('include_code', true));
        Audit::log('system.backup', null, null, ['file' => $name]);
        return $this->back('success', "Backup created: $name");
    }

    public function downloadBackup(Request $r, string $name): Response
    {
        $path = BackupService::path($name);
        if (!$path) {
            throw new HttpException(404);
        }
        Audit::log('system.backup_downloaded', null, null, ['file' => $name]);
        return Response::stream(fopen($path, 'rb'), $name, 'application/zip', filesize($path));
    }

    public function checkUpdate(Request $r): Response
    {
        return $this->json(UpdateService::check());
    }

    public function applyUpdate(Request $r): Response
    {
        $res = UpdateService::check();
        if (!$res['ok'] || empty($res['available'])) {
            return $this->json(['ok' => false, 'message' => $res['message'] ?? 'No update available.']);
        }
        return $this->json(UpdateService::apply($res['info']) + ['reload' => true]);
    }

    /** Offline update: upload the release zip + its signature (from latest.json). */
    public function uploadUpdate(Request $r): Response
    {
        $f = $r->file('package');
        $version = preg_replace('/[^0-9A-Za-z.\-]/', '', $r->str('version')) ?? '';
        $sig = $r->str('signature');
        $tooBig = ($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true))
            || (!$f && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST));
        if ($tooBig) {
            return $this->back('error', 'The package is larger than this server allows for uploads (upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size') . '). Use "Check for updates" (one-click), or raise both limits to 64M in cPanel → MultiPHP INI Editor.');
        }
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $version === '' || $sig === '') {
            return $this->back('error', 'Upload the package and enter its version and signature.');
        }
        $sha = hash_file('sha256', $f['tmp_name']);
        if (!UpdateService::verifySignature($version, $sha, $sig)) {
            return $this->back('error', 'Signature verification failed. The package was not installed.');
        }
        $tmp = NX_ROOT . '/storage/tmp/upload-update.zip';
        move_uploaded_file($f['tmp_name'], $tmp);
        $res = UpdateService::installPackage($tmp, $version, $sha);
        return $this->back($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function runCron(Request $r): Response
    {
        $report = CronService::run();
        return $this->json(['ok' => true, 'message' => 'Scheduler ran: ' . implode(', ', array_map(fn ($k, $v) => "$k=" . (is_scalar($v) ? $v : 'ok'), array_keys($report), $report))]);
    }

    public function rotateCronKey(Request $r): Response
    {
        Settings::set('cron.key', Crypto::token(24));
        return $this->back('success', 'Cron URL key rotated. Update your cron job URL.');
    }
}

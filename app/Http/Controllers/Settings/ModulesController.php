<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\Audit;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Http\Controllers\Controller;
use App\Services\ModuleManager;

final class ModulesController extends Controller
{
    public function index(Request $r): Response
    {
        $catalog = $r->query['catalog'] ?? null ? ModuleManager::catalog() : null;
        $installed = ModuleManager::installed();
        foreach ($installed as &$m) {
            $m['manifest'] = json_decode((string) $m['manifest'], true) ?: [];
        }
        return $this->view('settings/modules', [
            'title' => 'Modules', 'tab' => 'modules', 'installed' => $installed, 'catalog' => $catalog,
            'dev' => ModuleManager::developerMode(),
        ]);
    }

    public function upload(Request $r): Response
    {
        $f = $r->file('package');
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            return $this->back('error', 'Choose a module .zip file.', '/settings/modules');
        }
        $tmp = NX_ROOT . '/storage/tmp/module-upload-' . bin2hex(random_bytes(6)) . '.zip';
        if (!move_uploaded_file($f['tmp_name'], $tmp)) {
            return $this->back('error', 'The upload could not be saved.', '/settings/modules');
        }
        try {
            $res = ModuleManager::install($tmp, trim($r->str('signature')) ?: null);
        } catch (HttpException $e) {
            return $this->back('error', $e->getMessage(), '/settings/modules');
        } finally {
            @unlink($tmp);
        }
        return $this->back('success', ($res['upgraded'] ? 'Updated ' : 'Installed ') . $res['name'] . ' ' . $res['version'] . ($res['upgraded'] ? '.' : ' — enable it to start using it.'), '/settings/modules');
    }

    public function catalogInstall(Request $r, string $slug): Response
    {
        $res = ModuleManager::installFromCatalog($slug);
        return $this->json(['ok' => true, 'message' => 'Installed ' . $res['name'] . ' ' . $res['version'] . '.', 'reload' => true]);
    }

    public function toggle(Request $r, string $slug): Response
    {
        ModuleManager::setEnabled($slug, (bool) $r->input('enabled'));
        return $this->json(['ok' => true, 'message' => $r->input('enabled') ? 'Module enabled.' : 'Module disabled.', 'reload' => true]);
    }

    public function uninstall(Request $r, string $slug): Response
    {
        ModuleManager::uninstall($slug);
        return $this->json(['ok' => true, 'message' => 'Module removed.', 'reload' => true]);
    }

    public function developer(Request $r): Response
    {
        $on = (bool) $r->input('enabled');
        if ($on && $r->str('confirm') !== 'I understand') {
            throw new HttpException(422, 'Type "I understand" to turn on Developer mode.');
        }
        Settings::set('modules.allow_unsigned', $on, 0);
        Audit::log('modules.developer_mode', null, null, ['enabled' => $on]);
        if (!$on) {
            // Turning developer mode off also switches off any unsigned module.
            \App\Core\App::db()->query('UPDATE installed_modules SET enabled = 0 WHERE signed = 0');
        }
        return $this->json(['ok' => true, 'message' => $on ? 'Developer mode is on.' : 'Developer mode is off. Unsigned modules were disabled.', 'reload' => true]);
    }
}

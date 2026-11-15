<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\EntityRegistry;

final class CustomFieldController extends Controller
{
    public function index(Request $r): Response
    {
        $rows = App::db()->all('SELECT * FROM custom_fields WHERE company_id = ? ORDER BY entity, sort, id', [Auth::companyId()]);
        $entities = array_map(fn ($d) => $d['label'], EntityRegistry::all());
        asort($entities);
        return $this->view('settings/fields', ['title' => 'Custom fields', 'tab' => 'fields', 'fields' => $rows, 'entities' => $entities]);
    }

    public function store(Request $r): Response
    {
        $entity = $r->str('entity');
        if (!EntityRegistry::exists($entity)) {
            throw new HttpException(422, 'Choose a module.');
        }
        $label = mb_substr(trim($r->str('label')), 0, 100);
        $type = $r->str('type');
        if ($label === '' || !array_key_exists($type, EntityRegistry::CUSTOM_TYPES)) {
            throw new HttpException(422, 'Enter a label and choose a type.');
        }
        $id = $r->int('id');
        $options = array_values(array_unique(array_filter(array_map(fn ($v) => mb_substr(trim($v), 0, 60), preg_split('/\r?\n/', $r->str('options')) ?: []), fn ($v) => $v !== '')));
        if (in_array($type, ['select', 'multiselect'], true) && !$options) {
            throw new HttpException(422, 'Add at least one option (one per line).');
        }
        $db = App::db();
        $data = ['label' => $label, 'type' => $type, 'options' => json_encode($options), 'required' => $r->input('required') ? 1 : 0, 'show_in_list' => $r->input('show_in_list') ? 1 : 0, 'sort' => $r->int('sort')];
        if ($id) {
            if (!$db->value('SELECT id FROM custom_fields WHERE id = ? AND company_id = ?', [$id, Auth::companyId()])) {
                throw new HttpException(404);
            }
            $db->update('custom_fields', $data, ['id' => $id]);
        } else {
            $base = 'cf_' . trim(substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? 'field', 0, 40), '_');
            $name = $base;
            $i = 2;
            while ($db->value('SELECT id FROM custom_fields WHERE company_id = ? AND entity = ? AND name = ?', [Auth::companyId(), $entity, $name]) || isset(EntityRegistry::all()[$entity]['fields'][$name])) {
                $name = $base . '_' . $i++;
            }
            $db->insert('custom_fields', $data + ['company_id' => Auth::companyId(), 'entity' => $entity, 'name' => $name, 'created_at' => DB::now()]);
        }
        EntityRegistry::flush();
        Audit::log('settings.custom_field', $entity, null, ['label' => $label, 'type' => $type]);
        return $this->redirect('/settings/fields', 'success', 'Custom field saved.');
    }

    public function destroy(Request $r, string $id): Response
    {
        App::db()->query('DELETE FROM custom_fields WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        return $this->json(['ok' => true, 'message' => 'Field removed (existing values are kept in the record data).', 'reload' => true]);
    }
}

<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\EntityRegistry;
use App\Services\LookupService;
use App\Services\WorkflowService;

final class WorkflowController extends Controller
{
    public function index(Request $r): Response
    {
        $flows = App::db()->all('SELECT w.*, (SELECT status FROM workflow_runs wr WHERE wr.workflow_id = w.id ORDER BY wr.id DESC LIMIT 1) AS last_status FROM workflows w WHERE w.company_id = ? ORDER BY w.entity, w.name', [Auth::companyId()]);
        $runs = App::db()->all('SELECT wr.*, w.name FROM workflow_runs wr JOIN workflows w ON w.id = wr.workflow_id WHERE w.company_id = ? ORDER BY wr.id DESC LIMIT 30', [Auth::companyId()]);
        return $this->view('settings/workflows', ['title' => 'Automation', 'tab' => 'workflows', 'flows' => $flows, 'runs' => $runs]);
    }

    public function edit(Request $r, ?string $id = null): Response
    {
        $wf = $id ? App::db()->one('SELECT * FROM workflows WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]) : null;
        if ($id && !$wf) {
            throw new HttpException(404);
        }
        $entities = [];
        foreach (EntityRegistry::all() as $k => $d) {
            $def = EntityRegistry::get($k);
            $fields = [];
            foreach ($def['fields'] as $n => $f) {
                if ($f['type'] === 'hidden' || !empty($f['sensitive'])) {
                    continue;
                }
                $fields[] = ['name' => $n, 'label' => $f['label'], 'type' => $f['type'], 'options' => LookupService::fieldOptions($f), 'readonly' => !empty($f['readonly']) || !empty($f['system'])];
            }
            $entities[$k] = ['label' => $d['label'], 'fields' => $fields];
        }
        return $this->view('settings/workflow_edit', [
            'title' => $wf ? 'Edit automation' : 'New automation', 'tab' => 'workflows', 'wf' => $wf, 'entities' => $entities,
            'users' => LookupService::companyUsers(),
        ]);
    }

    public function save(Request $r): Response
    {
        $in = $r->json() ?: $r->all();
        $entity = (string) ($in['entity'] ?? '');
        $trigger = (string) ($in['trigger_type'] ?? '');
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        if ($name === '' || !EntityRegistry::exists($entity) || !array_key_exists($trigger, WorkflowService::TRIGGERS)) {
            throw new HttpException(422, 'Name, module and trigger are required.');
        }
        $fields = EntityRegistry::get($entity)['fields'];
        $conds = [];
        foreach ((array) ($in['conditions'] ?? []) as $c) {
            if (isset($fields[$c['field'] ?? '']) && array_key_exists($c['op'] ?? '', WorkflowService::CONDITION_OPS)) {
                $conds[] = ['field' => $c['field'], 'op' => $c['op'], 'value' => mb_substr((string) ($c['value'] ?? ''), 0, 500)];
            }
        }
        $actions = [];
        foreach ((array) ($in['actions'] ?? []) as $a) {
            $type = (string) ($a['type'] ?? '');
            if (!array_key_exists($type, WorkflowService::actions())) {
                continue;
            }
            if ($type === 'webhook') {
                $url = (string) ($a['url'] ?? '');
                if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                    throw new HttpException(422, 'Webhook action needs a valid http(s) URL.');
                }
            }
            if ($type === 'update_field' && !isset($fields[$a['field'] ?? ''])) {
                throw new HttpException(422, 'Choose the field to update.');
            }
            $clean = ['type' => $type];
            foreach (['field', 'value', 'user_id', 'title', 'description', 'due_in_days', 'priority', 'assign_to', 'to', 'email', 'subject', 'body', 'url', 'template', 'sequence_id'] as $k) {
                if (isset($a[$k]) && is_scalar($a[$k])) {
                    $clean[$k] = mb_substr((string) $a[$k], 0, 5000);
                }
            }
            if ($type === 'round_robin') {
                $clean['user_ids'] = array_values(array_map('intval', (array) ($a['user_ids'] ?? [])));
            }
            $actions[] = $clean;
        }
        if (!$actions) {
            throw new HttpException(422, 'Add at least one action.');
        }
        $cfg = [];
        if ($trigger === 'field_changed') {
            if (!isset($fields[$in['trigger_config']['field'] ?? ''])) {
                throw new HttpException(422, 'Choose the field to watch.');
            }
            $cfg = ['field' => $in['trigger_config']['field'], 'to' => mb_substr((string) ($in['trigger_config']['to'] ?? ''), 0, 200)];
        } elseif ($trigger === 'scheduled') {
            $df = $in['trigger_config']['date_field'] ?? '';
            if (!isset($fields[$df]) || !in_array($fields[$df]['type'], ['date', 'datetime'], true)) {
                throw new HttpException(422, 'Choose a date field.');
            }
            $cfg = ['date_field' => $df, 'offset_days' => max(-365, min(365, (int) ($in['trigger_config']['offset_days'] ?? 0)))];
        }
        $db = App::db();
        $id = (int) ($in['id'] ?? 0);
        $data = ['name' => $name, 'entity' => $entity, 'trigger_type' => $trigger, 'trigger_config' => json_encode($cfg), 'conditions' => json_encode($conds), 'actions' => json_encode($actions), 'is_active' => !empty($in['is_active']) ? 1 : 0, 'updated_at' => DB::now()];
        if ($id) {
            $existing = $db->one('SELECT * FROM workflows WHERE id = ? AND company_id = ?', [$id, Auth::companyId()]);
            if (!$existing) {
                throw new HttpException(404);
            }
            if ($trigger === 'webhook' && empty($existing['webhook_token'])) {
                $data['webhook_token'] = Crypto::token(24);
            }
            $db->update('workflows', $data, ['id' => $id]);
        } else {
            $data += ['company_id' => Auth::companyId(), 'created_by' => Auth::id(), 'created_at' => DB::now(), 'webhook_token' => $trigger === 'webhook' ? Crypto::token(24) : null];
            $id = $db->insert('workflows', $data);
        }
        Audit::log('workflow.saved', null, $id, ['name' => $name]);
        return $this->json(['ok' => true, 'message' => 'Automation saved', 'redirect' => url('/settings/workflows/' . $id)]);
    }

    public function toggle(Request $r, string $id): Response
    {
        App::db()->query('UPDATE workflows SET is_active = 1 - is_active WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        return $this->json(['ok' => true, 'message' => 'Updated', 'reload' => true]);
    }

    public function destroy(Request $r, string $id): Response
    {
        App::db()->query('DELETE FROM workflows WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        return $this->json(['ok' => true, 'message' => 'Automation deleted', 'redirect' => url('/settings/workflows')]);
    }
}

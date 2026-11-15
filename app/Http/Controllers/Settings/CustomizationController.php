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
use App\Core\Settings;
use App\Http\Controllers\Controller;
use App\Services\LookupService;

final class CustomizationController extends Controller
{
    public const PICKLISTS = [
        'lead_status' => 'Lead statuses', 'lead_source' => 'Lead sources', 'industry' => 'Industries', 'customer_type' => 'Customer types',
        'lost_reason' => 'Lost reasons', 'task_status' => 'Task statuses', 'project_status' => 'Project statuses', 'ticket_status' => 'Ticket statuses',
        'ticket_type' => 'Ticket types', 'expense_category' => 'Expense categories', 'payment_method' => 'Payment methods', 'leave_type' => 'Leave types', 'kb_category' => 'Knowledge base categories',
    ];

    public function index(Request $r): Response
    {
        $picklists = [];
        foreach (self::PICKLISTS as $k => $label) {
            $picklists[$k] = ['label' => $label, 'values' => (array) setting('picklist.' . $k, [])];
        }
        return $this->view('settings/customization', [
            'title' => 'Customization', 'tab' => 'customization', 'picklists' => $picklists,
            'pipelines' => LookupService::pipelines(), 'taxes' => App::db()->all('SELECT * FROM taxes WHERE company_id = ? ORDER BY rate', [Auth::companyId()]),
            'currencies' => App::db()->all('SELECT * FROM currencies ORDER BY code'),
            'sla' => (array) setting('sla.policies', []), 'scoring' => (array) setting('lead_scoring.rules', []),
            'leadFields' => \App\Services\EntityRegistry::get('leads')['fields'],
        ]);
    }

    public function savePicklists(Request $r): Response
    {
        foreach (self::PICKLISTS as $k => $label) {
            $raw = $r->input($k);
            if ($raw === null) {
                continue;
            }
            $values = array_values(array_unique(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 60), preg_split('/\r?\n/', (string) $raw) ?: []), fn ($v) => $v !== '')));
            if (in_array($k, ['task_status', 'ticket_status'], true)) {
                $required = $k === 'task_status' ? ['Done'] : ['Resolved', 'Closed'];
                foreach ($required as $req) {
                    if (!in_array($req, $values, true)) {
                        $values[] = $req; // statuses with built-in behaviour must exist
                    }
                }
            }
            if ($k === 'lead_status' && !in_array('Converted', $values, true)) {
                $values[] = 'Converted';
            }
            Settings::set('picklist.' . $k, array_slice($values, 0, 100), Auth::companyId());
        }
        Audit::log('settings.picklists');
        return $this->back('success', 'Lists saved.');
    }

    public function savePipelines(Request $r): Response
    {
        $db = App::db();
        $cid = Auth::companyId();
        $data = json_decode($r->str('pipelines'), true);
        if (!is_array($data)) {
            throw new HttpException(422, 'Invalid data.');
        }
        $db->transaction(function () use ($db, $cid, $data) {
            $keep = [];
            foreach (array_values($data) as $i => $p) {
                $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 100);
                if ($name === '') {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                if ($pid && $db->value('SELECT id FROM pipelines WHERE id = ? AND company_id = ?', [$pid, $cid])) {
                    $db->update('pipelines', ['name' => $name, 'sort' => $i, 'is_default' => $i === 0 ? 1 : 0], ['id' => $pid]);
                } else {
                    $pid = $db->insert('pipelines', ['company_id' => $cid, 'name' => $name, 'sort' => $i, 'is_default' => $i === 0 ? 1 : 0, 'created_at' => DB::now()]);
                }
                $keep[] = $pid;
                $keepStages = [];
                foreach (array_values((array) ($p['stages'] ?? [])) as $j => $s) {
                    $sn = mb_substr(trim((string) ($s['name'] ?? '')), 0, 100);
                    if ($sn === '') {
                        continue;
                    }
                    $row = ['name' => $sn, 'probability' => max(0, min(100, (int) ($s['probability'] ?? 0))), 'color' => in_array($s['color'] ?? '', ['slate', 'blue', 'violet', 'amber', 'green', 'red', 'teal', 'rose', 'indigo'], true) ? $s['color'] : 'slate', 'sort' => $j, 'is_won' => !empty($s['is_won']) ? 1 : 0, 'is_lost' => !empty($s['is_lost']) ? 1 : 0];
                    $sid = (int) ($s['id'] ?? 0);
                    if ($sid && $db->value('SELECT id FROM pipeline_stages WHERE id = ? AND pipeline_id = ?', [$sid, $pid])) {
                        $db->update('pipeline_stages', $row, ['id' => $sid]);
                    } else {
                        $sid = $db->insert('pipeline_stages', $row + ['pipeline_id' => $pid]);
                    }
                    $keepStages[] = $sid;
                }
                if ($keepStages) {
                    $in = implode(',', array_map('intval', $keepStages));
                    $orphans = $db->column("SELECT id FROM pipeline_stages WHERE pipeline_id = ? AND id NOT IN ($in)", [$pid]);
                    foreach ($orphans as $o) {
                        if ($db->value('SELECT id FROM deals WHERE stage_id = ? AND deleted_at IS NULL LIMIT 1', [$o])) {
                            throw new HttpException(422, 'A stage that still has deals cannot be removed. Move the deals first.');
                        }
                        $db->delete('pipeline_stages', ['id' => $o]);
                    }
                }
            }
            if (!$keep) {
                throw new HttpException(422, 'Keep at least one pipeline.');
            }
            $in = implode(',', array_map('intval', $keep));
            foreach ($db->column("SELECT id FROM pipelines WHERE company_id = ? AND id NOT IN ($in)", [$cid]) as $old) {
                if ($db->value('SELECT id FROM deals WHERE pipeline_id = ? AND deleted_at IS NULL LIMIT 1', [$old])) {
                    throw new HttpException(422, 'A pipeline that still has deals cannot be removed.');
                }
                $db->delete('pipeline_stages', ['pipeline_id' => $old]);
                $db->delete('pipelines', ['id' => $old]);
            }
        });
        Audit::log('settings.pipelines');
        return $this->json(['ok' => true, 'message' => 'Pipelines saved', 'reload' => true]);
    }

    public function saveTaxes(Request $r): Response
    {
        $db = App::db();
        $cid = Auth::companyId();
        $rows = json_decode($r->str('taxes'), true) ?: [];
        $db->transaction(function () use ($db, $cid, $rows) {
            $keep = [];
            $default = null;
            foreach ($rows as $t) {
                $name = mb_substr(trim((string) ($t['name'] ?? '')), 0, 60);
                if ($name === '') {
                    continue;
                }
                $data = ['name' => $name, 'rate' => max(0, min(100, (float) ($t['rate'] ?? 0))), 'is_default' => !empty($t['is_default']) && $default === null ? 1 : 0];
                if ($data['is_default']) {
                    $default = true;
                }
                $id = (int) ($t['id'] ?? 0);
                if ($id && $db->value('SELECT id FROM taxes WHERE id = ? AND company_id = ?', [$id, $cid])) {
                    $db->update('taxes', $data, ['id' => $id]);
                } else {
                    $id = $db->insert('taxes', $data + ['company_id' => $cid, 'created_at' => DB::now()]);
                }
                $keep[] = $id;
            }
            $in = $keep ? implode(',', array_map('intval', $keep)) : '0';
            $db->query("DELETE FROM taxes WHERE company_id = ? AND id NOT IN ($in)", [$cid]);
        });
        return $this->json(['ok' => true, 'message' => 'Taxes saved', 'reload' => true]);
    }

    public function saveCurrencies(Request $r): Response
    {
        $db = App::db();
        foreach ((array) $r->input('currencies', []) as $code => $c) {
            if (!preg_match('/^[A-Z]{3}$/', (string) $code)) {
                continue;
            }
            $db->update('currencies', ['rate' => max(0.000001, (float) ($c['rate'] ?? 1)), 'is_active' => !empty($c['active']) ? 1 : 0, 'updated_at' => DB::now()], ['code' => $code]);
        }
        $new = strtoupper($r->str('new_code'));
        if (preg_match('/^[A-Z]{3}$/', $new) && !$db->value('SELECT id FROM currencies WHERE code = ?', [$new])) {
            $db->insert('currencies', ['code' => $new, 'name' => mb_substr($r->str('new_name'), 0, 60) ?: $new, 'rate' => max(0.000001, (float) $r->str('new_rate') ?: 1), 'is_active' => 1, 'updated_at' => DB::now()]);
        }
        $company = $db->value('SELECT currency FROM companies WHERE id = ?', [Auth::companyId()]);
        $db->update('currencies', ['is_active' => 1], ['code' => $company]);
        return $this->back('success', 'Currencies saved.');
    }

    public function saveSla(Request $r): Response
    {
        $out = [];
        foreach (['Urgent', 'High', 'Medium', 'Low'] as $p) {
            $out[$p] = ['response' => max(1, min(720, $r->int('sla_' . $p . '_response', 8))), 'resolution' => max(1, min(2160, $r->int('sla_' . $p . '_resolution', 72)))];
        }
        Settings::set('sla.policies', $out, Auth::companyId());
        return $this->back('success', 'SLA policies saved.');
    }

    public function saveScoring(Request $r): Response
    {
        $rules = json_decode($r->str('rules'), true) ?: [];
        $fields = \App\Services\EntityRegistry::get('leads')['fields'];
        $clean = [];
        foreach (array_slice($rules, 0, 50) as $rule) {
            if (isset($fields[$rule['field'] ?? '']) && in_array($rule['op'] ?? '', ['filled', 'eq', 'gt', 'contains'], true)) {
                $clean[] = ['field' => $rule['field'], 'op' => $rule['op'], 'value' => mb_substr((string) ($rule['value'] ?? ''), 0, 100), 'points' => max(-100, min(100, (int) ($rule['points'] ?? 0)))];
            }
        }
        Settings::set('lead_scoring.rules', $clean, Auth::companyId());
        return $this->json(['ok' => true, 'message' => 'Lead scoring rules saved']);
    }
}

<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Core\Xlsx;
use App\Services\ActivityService;
use App\Services\EntityRegistry;
use App\Services\FieldRenderer;
use App\Services\FileService;
use App\Services\ImportService;
use App\Services\LookupService;
use App\Services\ProjectService;
use App\Services\RecordService;
use App\Services\SalesService;
use App\Services\WorkflowService;

/**
 * Generic controller for every metadata-defined entity: list, board, detail, create, update,
 * inline edit, bulk actions, export, import, relation lookups.
 */
final class RecordController extends Controller
{
    /** Parse list parameters from the query string. */
    public static function listParams(Request $r, RecordService $svc): array
    {
        $filters = [];
        foreach ((array) ($r->query['f'] ?? []) as $f) {
            if (!is_string($f)) {
                continue;
            }
            $parts = explode(':', $f, 3);
            if (count($parts) < 2) {
                continue;
            }
            [$field, $op] = $parts;
            $value = $parts[2] ?? '';
            $value2 = null;
            if ($op === 'between' && str_contains($value, '..')) {
                [$value, $value2] = explode('..', $value, 2);
            }
            if ($op === 'in') {
                $value = explode(',', $value);
            }
            $filters[] = ['field' => $field, 'op' => $op, 'value' => $value, 'value2' => $value2];
        }
        $scope = (string) ($r->query['scope'] ?? '');
        if ($scope === 'mine' && $svc->hasAssigned()) {
            $filters[] = ['field' => 'assigned_to', 'op' => 'me'];
        }
        return [
            'q' => (string) ($r->query['q'] ?? ''),
            'filters' => $filters,
            'sort' => (string) ($r->query['sort'] ?? ''),
            'dir' => (string) ($r->query['dir'] ?? ''),
            'page' => (int) ($r->query['page'] ?? 1),
            'per_page' => (int) ($r->query['per_page'] ?? 0) ?: (int) (Auth::user()['prefs']['per_page'] ?? setting('app.per_page', 25)),
        ];
    }

    public static function columnsFor(RecordService $svc): array
    {
        $fields = EntityRegistry::visibleFields($svc->def);
        $saved = Auth::user()['prefs']['columns'][$svc->entity] ?? null;
        if (is_array($saved) && $saved) {
            $cols = array_values(array_filter($saved, fn ($c) => isset($fields[$c]) && $fields[$c]['type'] !== 'hidden'));
            if ($cols) {
                return $cols;
            }
        }
        return array_keys(array_filter($fields, fn ($f) => !empty($f['list'])));
    }

    public function index(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        Permissions::authorize($svc->module, 'view');
        $view = (string) ($r->query['view'] ?? '');
        $canBoard = !empty($svc->def['board']);
        if ($view === '') {
            $view = $canBoard && ($svc->def['default_view'] ?? '') === 'board' ? 'board' : 'list';
        }
        if ($view === 'board' && !$canBoard) {
            $view = 'list';
        }
        $params = self::listParams($r, $svc);
        $data = [
            'title' => $svc->def['label'],
            'svc' => $svc,
            'def' => $svc->def,
            'entity' => $entity,
            'viewMode' => $view,
            'params' => $params,
            'columns' => self::columnsFor($svc),
            'savedViews' => App::db()->all('SELECT id, name, config, is_shared, user_id FROM saved_views WHERE company_id = ? AND entity = ? AND (user_id = ? OR is_shared = 1) ORDER BY sort, name', [Auth::companyId(), $entity, Auth::id()]),
        ];
        if ($view === 'board') {
            $data += $this->boardData($svc, $params, $r);
        } else {
            $data['result'] = $svc->list($params);
        }
        return $this->view('records/index', $data);
    }

    /** HTML fragment for the list (used by Alpine to refresh without a full reload). */
    public function data(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        Permissions::authorize($svc->module, 'view');
        $params = self::listParams($r, $svc);
        if (($r->query['view'] ?? '') === 'board' && !empty($svc->def['board'])) {
            $board = $this->boardData($svc, $params, $r);
            return $this->json(['html' => View::partial('records/_board', ['svc' => $svc, 'entity' => $entity] + $board), 'total' => $board['boardTotal']]);
        }
        $result = $svc->list($params);
        $columns = self::columnsFor($svc);
        return $this->json([
            'html' => View::partial('records/_table', ['svc' => $svc, 'entity' => $entity, 'result' => $result, 'columns' => $columns, 'params' => $params]),
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
        ]);
    }

    private function boardData(RecordService $svc, array $params, Request $r): array
    {
        $field = $svc->def['board'];
        $fd = $svc->def['fields'][$field];
        $columns = [];
        $pipelineId = null;
        if ($fd['type'] === 'stage') {
            $pipelines = LookupService::pipelines();
            $pipelineId = (int) ($r->query['pipeline'] ?? 0) ?: (int) ($pipelines[0]['id'] ?? 0);
            foreach ($pipelines as $p) {
                if ((int) $p['id'] === $pipelineId) {
                    foreach ($p['stages'] as $s) {
                        $columns[] = ['value' => (string) $s['id'], 'label' => $s['name'], 'color' => $s['color'], 'meta' => $s];
                    }
                }
            }
            $params['filters'][] = ['field' => 'pipeline_id', 'op' => 'eq', 'value' => $pipelineId];
        } else {
            foreach (EntityRegistry::options($fd) as $v => $l) {
                $columns[] = ['value' => (string) $v, 'label' => $l, 'color' => status_color($l)];
            }
        }
        $params['per_page'] = 500;
        $params['max_per_page'] = 500;
        $params['page'] = 1;
        $params['board'] = true;
        $result = $svc->list($params);
        $counts = $svc->groupCounts($field, $params);
        $byCol = [];
        foreach ($result['rows'] as $row) {
            $byCol[(string) ($row[$field] ?? '')][] = $row;
        }
        if (isset($byCol[''])) {
            array_unshift($columns, ['value' => '', 'label' => 'No ' . strtolower($fd['label']), 'color' => 'slate']);
        }
        return ['boardColumns' => $columns, 'boardCards' => $byCol, 'boardCounts' => $counts, 'boardField' => $field, 'pipelineId' => $pipelineId, 'boardTotal' => $result['total']];
    }

    public function show(Request $r, string $entity, string $id): Response
    {
        $svc = $this->service($entity);
        $record = $svc->find((int) $id);
        $related = [];
        foreach ($svc->def['related'] as $rel) {
            if (!EntityRegistry::exists($rel['entity'])) {
                continue;
            }
            $rsvc = RecordService::for($rel['entity']);
            if (!can($rsvc->module, 'view') || !module_enabled($rsvc->module)) {
                continue;
            }
            $res = $rsvc->list(['related' => !empty($rel['morph']) ? ['morph' => true, 'entity' => $entity, 'id' => (int) $id] : ['fk' => $rel['fk'], 'id' => (int) $id], 'per_page' => 50]);
            $related[] = ['def' => $rel, 'svc' => $rsvc, 'result' => $res];
        }
        $extra = [];
        if ($entity === 'projects') {
            $extra['projectStats'] = ProjectService::stats((int) $id);
            $extra['members'] = ProjectService::members((int) $id);
        }
        if ($entity === 'tickets') {
            $extra['replies'] = App::db()->all('SELECT tr.*, u.name AS user_name, u.email AS user_email FROM ticket_replies tr LEFT JOIN users u ON u.id = tr.user_id WHERE tr.ticket_id = ? ORDER BY tr.id', [(int) $id]);
        }
        if ($entity === 'quotes' || $entity === 'invoices') {
            $extra['doc'] = SalesService::documentData($entity === 'quotes' ? 'quote' : 'invoice', $record);
        }
        if (in_array($entity, ['tasks', 'projects'], true)) {
            $extra['timer'] = ProjectService::runningTimer();
        }
        $workflows = Auth::user()['is_admin'] || can('workflows', 'view')
            ? App::db()->all("SELECT id, name FROM workflows WHERE company_id = ? AND entity = ? AND trigger_type = 'manual' AND is_active = 1", [Auth::companyId(), $entity])
            : [];
        return $this->view('records/show', [
            'title' => $record['_title'] . ' · ' . $svc->def['singular'],
            'svc' => $svc, 'def' => $svc->def, 'entity' => $entity, 'record' => $record,
            'related' => $related,
            'activities' => ActivityService::forRecord($entity, (int) $id),
            'files' => FileService::forRecord($entity, (int) $id),
            'canEdit' => Permissions::can($svc->module, 'edit', $record),
            'canDelete' => Permissions::can($svc->module, 'delete', $record),
            'history' => Auth::user()['is_admin'] ? App::db()->all("SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity = ? AND a.record_id = ? AND a.company_id = ? ORDER BY a.id DESC LIMIT 50", [$entity, (int) $id, Auth::companyId()]) : [],
            'manualWorkflows' => $workflows,
        ] + $extra);
    }

    /** Drawer form (HTML fragment) for create or edit. */
    public function form(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        $id = (int) ($r->query['id'] ?? 0);
        $record = $id ? $svc->find($id, 'edit') : null;
        if (!$id) {
            Permissions::authorize($svc->module, 'create');
        }
        $prefill = [];
        foreach ((array) ($r->query['prefill'] ?? []) as $k => $v) {
            if (is_string($k) && isset($svc->def['fields'][$k]) && is_scalar($v)) {
                $prefill[$k] = (string) $v;
            }
        }
        $quick = !$id && ($r->query['quick'] ?? '') === '1' && !empty($svc->def['quick']);
        return $this->json(['html' => View::partial('records/_form', ['svc' => $svc, 'entity' => $entity, 'record' => $record, 'prefill' => $prefill, 'quick' => $quick])]);
    }

    public function store(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        if (in_array($entity, ['quotes', 'invoices'], true)) {
            throw new HttpException(400, 'Use the document editor.');
        }
        $input = $this->input($r, $svc);
        $record = $svc->create($input);
        return $this->json(['ok' => true, 'id' => $record['id'], 'title' => $record['_title'], 'message' => $svc->def['singular'] . ' created', 'warnings' => $svc->warnings, 'redirect' => $svc->url((int) $record['id'])]);
    }

    public function update(Request $r, string $entity, string $id): Response
    {
        $svc = $this->service($entity);
        $record = $svc->update((int) $id, $this->input($r, $svc));
        return $this->json(['ok' => true, 'id' => $record['id'], 'message' => 'Changes saved', 'warnings' => $svc->warnings]);
    }

    /** Inline edit of a single field; returns the rendered value. */
    public function patch(Request $r, string $entity, string $id): Response
    {
        $svc = $this->service($entity);
        $field = $r->str('field');
        if (!isset($svc->def['fields'][$field])) {
            throw new HttpException(422, 'Unknown field.');
        }
        $record = $svc->update((int) $id, [$field => $r->input('value')]);
        $f = $svc->def['fields'][$field];
        return $this->json(['ok' => true, 'html' => FieldRenderer::html($f, $record, $svc), 'value' => $record[$field] ?? null, 'record' => ['status' => $record['status'] ?? null], 'warnings' => $svc->warnings]);
    }

    /** Kanban move: change the board field (and order). */
    public function move(Request $r, string $entity, string $id): Response
    {
        $svc = $this->service($entity);
        $field = $svc->def['board'] ?? null;
        if (!$field) {
            throw new HttpException(400);
        }
        $value = $r->input('value');
        $record = $svc->update((int) $id, [$field => $value === '' ? null : $value]);
        $order = array_values(array_filter(array_map('intval', (array) $r->input('order', []))));
        if ($order && !empty($svc->def['sortable_board'])) {
            $db = App::db();
            foreach (array_slice($order, 0, 500) as $i => $rid) {
                $db->query('UPDATE ' . $db->ident($svc->table) . ' SET sort = ? WHERE id = ? AND company_id = ?', [$i, $rid, Auth::companyId()]);
            }
        }
        return $this->json(['ok' => true, 'message' => $svc->def['singular'] . ' moved', 'warnings' => $svc->warnings, 'probability' => $record['probability'] ?? null]);
    }

    public function destroy(Request $r, string $entity, string $id): Response
    {
        $svc = $this->service($entity);
        $svc->delete((int) $id);
        return $this->done($r, $svc->def['singular'] . ' deleted', '/r/' . $entity);
    }

    public function bulk(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        $ids = array_slice(array_values(array_filter(array_map('intval', (array) $r->input('ids', [])))), 0, 500);
        $action = $r->str('action');
        if (!$ids) {
            throw new HttpException(422, 'Select at least one record.');
        }
        $ok = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                match ($action) {
                    'delete' => $svc->delete($id),
                    'update' => $svc->update($id, [$r->str('field') => $r->input('value')]),
                    default => throw new HttpException(422, 'Unknown action.'),
                };
                $ok++;
            } catch (HttpException $e) {
                if ($e->status === 422 && $action === 'update' && $ok === 0 && $failed === 0) {
                    throw $e;
                }
                $failed++;
            }
        }
        $msg = "$ok record(s) " . ($action === 'delete' ? 'deleted' : 'updated') . ($failed ? ", $failed skipped (no permission)" : '');
        return $this->json(['ok' => true, 'message' => $msg]);
    }

    public function export(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        Permissions::authorize($svc->module, 'export');
        $params = self::listParams($r, $svc);
        $params['per_page'] = 10000;
        $params['max_per_page'] = 10000;
        $params['page'] = 1;
        if (!empty($r->query['ids'])) {
            $params['ids'] = explode(',', (string) $r->query['ids']);
        }
        $result = $svc->list($params);
        $fields = array_filter(EntityRegistry::visibleFields($svc->def), fn ($f) => $f['type'] !== 'hidden');
        $headers = array_merge(['ID'], array_map(fn ($f) => $f['label'], array_values($fields)));
        $rows = [];
        foreach ($result['rows'] as $row) {
            $line = [(int) $row['id']];
            foreach ($fields as $name => $f) {
                $line[] = FieldRenderer::plain($f, $row[$name] ?? null, $svc, $row);
            }
            $rows[] = $line;
        }
        Audit::log('records.exported', $entity, null, ['count' => count($rows), 'format' => $r->query['format'] ?? 'csv']);
        $base = $entity . '-' . date('Y-m-d');
        if (($r->query['format'] ?? 'csv') === 'xlsx') {
            return Response::download(Xlsx::build($headers, $rows, $svc->def['label']), $base . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        return Response::download(Xlsx::csv($headers, $rows), $base . '.csv', 'text/csv; charset=UTF-8');
    }

    public function importForm(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        Permissions::authorize($svc->module, 'import');
        $fields = array_filter(EntityRegistry::visibleFields($svc->def), [ImportService::class, 'importable']);
        return $this->view('records/import', ['title' => 'Import ' . $svc->def['label'], 'svc' => $svc, 'entity' => $entity, 'fields' => $fields, 'stage' => 'upload']);
    }

    public function importUpload(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        Permissions::authorize($svc->module, 'import');
        $file = $r->file('file');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return $this->redirect('/r/' . $entity . '/import', 'error', 'Choose a CSV file to upload.');
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv' || $file['size'] > 10 * 1048576) {
            return $this->redirect('/r/' . $entity . '/import', 'error', 'Upload a .csv file up to 10 MB.');
        }
        [$headers, $rows] = ImportService::parse($file['tmp_name']);
        $token = bin2hex(random_bytes(8));
        $path = NX_ROOT . '/storage/tmp/import-' . Auth::id() . '-' . $token . '.json';
        file_put_contents($path, json_encode(['entity' => $entity, 'headers' => $headers, 'rows' => $rows]));
        $fields = array_filter(EntityRegistry::visibleFields($svc->def), [ImportService::class, 'importable']);
        return $this->view('records/import', [
            'title' => 'Import ' . $svc->def['label'], 'svc' => $svc, 'entity' => $entity, 'fields' => $fields, 'stage' => 'map',
            'headers' => $headers, 'sample' => array_slice($rows, 0, 3), 'rowCount' => count($rows), 'token' => $token,
            'mapping' => ImportService::suggestMapping($entity, $headers),
        ]);
    }

    public function importRun(Request $r, string $entity): Response
    {
        $svc = $this->service($entity);
        $token = preg_replace('/[^a-f0-9]/', '', $r->str('token')) ?? '';
        $path = NX_ROOT . '/storage/tmp/import-' . Auth::id() . '-' . $token . '.json';
        if ($token === '' || !is_file($path)) {
            return $this->redirect('/r/' . $entity . '/import', 'error', 'The import session expired. Upload the file again.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (($data['entity'] ?? '') !== $entity) {
            throw new HttpException(400);
        }
        $mapping = array_map('strval', (array) $r->input('mapping', []));
        $dry = $r->str('mode') === 'validate';
        $result = ImportService::run($entity, $data['rows'], $mapping, $dry);
        if (!$dry) {
            @unlink($path);
            Session::flash($result['errors'] ? 'warning' : 'success', $result['created'] . ' record(s) imported' . ($result['errors'] ? ', ' . count($result['errors']) . ' row(s) skipped' : '') . '.');
            if (!$result['errors']) {
                return Response::redirect('/r/' . $entity);
            }
        }
        $fields = array_filter(EntityRegistry::visibleFields($svc->def), [ImportService::class, 'importable']);
        return $this->view('records/import', [
            'title' => 'Import ' . $svc->def['label'], 'svc' => $svc, 'entity' => $entity, 'fields' => $fields, 'stage' => $dry ? 'map' : 'done',
            'headers' => $data['headers'], 'sample' => array_slice($data['rows'], 0, 3), 'rowCount' => count($data['rows']), 'token' => $token,
            'mapping' => $mapping, 'result' => $result, 'dry' => $dry,
        ]);
    }

    /** Relation picker search. */
    public function lookup(Request $r, string $entity): Response
    {
        if ($entity === 'users') {
            $q = mb_strtolower($r->str('q'));
            $users = array_values(array_filter(LookupService::companyUsers(), fn ($u) => $q === '' || str_contains(mb_strtolower($u['name'] . ' ' . $u['email']), $q)));
            return $this->json(['items' => array_map(fn ($u) => ['id' => $u['id'], 'title' => $u['name'], 'subtitle' => $u['email']], array_slice($users, 0, 20))]);
        }
        $svc = $this->service($entity);
        $where = [];
        foreach ((array) ($r->query['where'] ?? []) as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $where[$k] = $v;
            }
        }
        return $this->json(['items' => LookupService::search($entity, $r->str('q'), 15, $where)]);
    }

    public function runWorkflow(Request $r, string $entity, string $id, string $wid): Response
    {
        $svc = $this->service($entity);
        $record = $svc->find((int) $id, 'edit');
        $wf = App::db()->one("SELECT * FROM workflows WHERE id = ? AND company_id = ? AND entity = ? AND trigger_type = 'manual' AND is_active = 1", [(int) $wid, Auth::companyId(), $entity]);
        if (!$wf) {
            throw new HttpException(404);
        }
        $raw = App::db()->find($svc->table, (int) $id);
        $res = WorkflowService::execute($wf, $entity, $raw);
        return $this->json(['ok' => $res['status'] !== 'failed', 'message' => $wf['name'] . ': ' . ($res['status'] === 'skipped' ? 'conditions not met' : implode('; ', $res['log']))]);
    }

    /** Collect only defined fields from the request body. */
    private function input(Request $r, RecordService $svc): array
    {
        $all = $r->all();
        $out = [];
        foreach ($svc->def['fields'] as $name => $f) {
            if (array_key_exists($name, $all)) {
                $out[$name] = $all[$name];
            } elseif ($f['type'] === 'bool' && array_key_exists('_present_' . $name, $all)) {
                $out[$name] = 0; // unchecked checkbox
            }
        }
        return $out;
    }
}

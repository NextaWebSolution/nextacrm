<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\EntityRegistry;
use App\Services\ReportBuilderService as RB;

final class ReportBuilderController extends Controller
{
    private function find(int $id, bool $forEdit = false): array
    {
        $rep = App::db()->one('SELECT * FROM custom_reports WHERE id = ? AND company_id = ?', [$id, Auth::companyId()]);
        if (!$rep || (!$rep['is_shared'] && (int) $rep['created_by'] !== Auth::id() && empty(Auth::user()['is_admin']))) {
            throw new HttpException(404);
        }
        if ($forEdit && (int) $rep['created_by'] !== Auth::id() && empty(Auth::user()['is_admin'])) {
            throw new HttpException(403, 'Only the owner can change this report.');
        }
        return $rep;
    }

    private function entities(): array
    {
        $out = [];
        foreach (EntityRegistry::all() as $k => $d) {
            if (can($d['module'], 'view') && module_enabled($d['module'])) {
                $out[$k] = ['label' => $d['label']] + RB::fieldsFor($k);
            }
        }
        return $out;
    }

    public function builder(Request $r, string $id = '0'): Response
    {
        $rep = (int) $id ? $this->find((int) $id, true) : null;
        $cfg = $rep ? (json_decode((string) $rep['config'], true) ?: []) : ['entity' => 'deals', 'group_by' => 'stage_id', 'metric' => 'sum', 'metric_field' => 'amount', 'chart' => 'bar', 'bucket' => 'month', 'filters' => [], 'sort' => 'value_desc', 'limit' => 25];
        View::share('breadcrumbs', ['Reports' => url('/reports')]);
        return $this->view('reports/builder', ['title' => $rep ? 'Edit report' : 'Report builder', 'rep' => $rep, 'cfg' => $cfg, 'entities' => $this->entities()]);
    }

    public function preview(Request $r): Response
    {
        $cfg = RB::normalize($r->json());
        return $this->json(['ok' => true] + RB::run($cfg) + ['chart' => $cfg['chart']]);
    }

    public function save(Request $r, string $id = '0'): Response
    {
        $in = $r->json();
        $cfg = RB::normalize((array) ($in['config'] ?? []));
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        if ($name === '') {
            throw new HttpException(422, 'Give the report a name.');
        }
        $recipients = implode(', ', array_slice(array_values(array_filter(array_map('trim', explode(',', (string) ($in['recipients'] ?? ''))), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))), 0, 10));
        $data = ['name' => $name, 'entity' => $cfg['entity'], 'config' => json_encode($cfg), 'is_shared' => !empty($in['is_shared']) ? 1 : 0,
            'schedule' => in_array($in['schedule'] ?? '', ['none', 'daily', 'weekly', 'monthly'], true) ? $in['schedule'] : 'none',
            'recipients' => $recipients ?: null, 'updated_at' => DB::now()];
        $db = App::db();
        if ((int) $id) {
            $this->find((int) $id, true);
            $db->update('custom_reports', $data, ['id' => (int) $id]);
        } else {
            $id = (string) $db->insert('custom_reports', $data + ['company_id' => Auth::companyId(), 'created_by' => Auth::id(), 'created_at' => DB::now()]);
        }
        Audit::log('report.saved', null, (int) $id, ['entity' => $cfg['entity']]);
        return $this->json(['ok' => true, 'message' => 'Report saved.', 'redirect' => url('/reports/custom/' . (int) $id)]);
    }

    public function show(Request $r, string $id): Response
    {
        $rep = $this->find((int) $id);
        $cfg = RB::normalize(json_decode((string) $rep['config'], true) ?: []);
        if (($r->query['format'] ?? '') === 'csv') {
            return Response::download(RB::csv($cfg, RB::run($cfg)), preg_replace('/[^A-Za-z0-9_\-]+/', '-', $rep['name']) . '.csv', 'text/csv');
        }
        View::share('breadcrumbs', ['Reports' => url('/reports')]);
        return $this->view('reports/custom_show', ['title' => $rep['name'], 'rep' => $rep, 'cfg' => $cfg, 'res' => RB::run($cfg), 'canEdit' => (int) $rep['created_by'] === Auth::id() || !empty(Auth::user()['is_admin'])]);
    }

    public function delete(Request $r, string $id): Response
    {
        $this->find((int) $id, true);
        App::db()->delete('custom_reports', ['id' => (int) $id]);
        return $this->json(['ok' => true, 'message' => 'Report deleted.', 'redirect' => url('/reports')]);
    }

    public static function listFor(): array
    {
        return App::db()->all('SELECT id, name, entity, schedule, is_shared, created_by FROM custom_reports WHERE company_id = ? AND (is_shared = 1 OR created_by = ?) ORDER BY name', [Auth::companyId(), Auth::id()]);
    }
}

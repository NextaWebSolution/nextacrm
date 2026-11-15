<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Services\ReportService;

final class ReportController extends Controller
{
    public function index(Request $r): Response
    {
        $catalog = array_filter(ReportService::catalog(), fn ($c) => can($c['module'], 'view') && module_enabled($c['module']));
        return $this->view('reports/index', ['title' => 'Reports', 'catalog' => $catalog]);
    }

    public function show(Request $r, string $key): Response
    {
        $catalog = ReportService::catalog();
        if (!isset($catalog[$key]) || !can($catalog[$key]['module'], 'view')) {
            throw new HttpException(404);
        }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r->query['from'] ?? '')) ? $r->query['from'] : date('Y-m-01', strtotime('-2 months'));
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r->query['to'] ?? '')) ? $r->query['to'] : date('Y-m-d');
        $data = ReportService::run($key, $from, $to);
        if (($r->query['export'] ?? '') === 'csv') {
            if (!can($catalog[$key]['module'], 'export')) {
                throw new HttpException(403);
            }
            $rows = $data['rows'];
            if (!empty($data['totals'])) {
                $rows[] = $data['totals'];
            }
            return Response::download(Xlsx::csv($data['columns'], $rows), 'report-' . $key . '-' . $from . '-to-' . $to . '.csv', 'text/csv; charset=UTF-8');
        }
        return $this->view('reports/show', ['title' => $catalog[$key]['title'], 'report' => $catalog[$key], 'key' => $key, 'data' => $data, 'from' => $from, 'to' => $to]);
    }
}

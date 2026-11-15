<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\ReportService;

final class DashboardController extends Controller
{
    public function index(Request $r): Response
    {
        return $this->view('dashboard', [
            'title' => 'Dashboard',
            'kpis' => ReportService::kpis(),
            'revenue' => ReportService::revenueSeries(12),
            'pipeline' => ReportService::pipelineByStage(),
            'leadSources' => ReportService::leadsBySource(90),
            'taskStatus' => ReportService::tasksByStatus(),
            'myTasks' => ReportService::myTasks(8),
            'events' => ReportService::upcomingEvents(5),
            'activity' => ActivityService::recent(12),
        ]);
    }
}

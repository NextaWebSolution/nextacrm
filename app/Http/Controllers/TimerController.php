<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectService;

final class TimerController extends Controller
{
    public function start(Request $r): Response
    {
        $t = ProjectService::startTimer($r->int('task_id') ?: null, $r->int('project_id') ?: null, $r->str('description'));
        return $this->json(['ok' => true, 'message' => 'Timer started', 'timer' => self::shape($t)]);
    }

    public function stop(Request $r): Response
    {
        $t = ProjectService::stopTimer();
        return $this->json(['ok' => true, 'message' => $t ? 'Logged ' . \App\Services\FieldRenderer::duration((int) $t['minutes']) : 'No timer running', 'timer' => null]);
    }

    public function status(Request $r): Response
    {
        return $this->json(['timer' => self::shape(ProjectService::runningTimer())]);
    }

    private static function shape(?array $t): ?array
    {
        if (!$t || empty($t['is_running'])) {
            return null;
        }
        return ['id' => (int) $t['id'], 'label' => $t['task_title'] ?? $t['description'] ?? 'Timer', 'project' => $t['project_name'] ?? null, 'started' => strtotime($t['started_at'] . ' UTC'), 'base' => (int) $t['minutes'] * 60, 'task_id' => $t['task_id'] ? (int) $t['task_id'] : null];
    }
}

<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Services\RecordService;

final class ChecklistController extends Controller
{
    /** POST /r/tasks/{id}/checklist  {items: [{t: "text", d: true|false}]} */
    public function save(Request $r, string $id): Response
    {
        $svc = RecordService::for('tasks');
        $task = $svc->find((int) $id);
        if (!Permissions::can('tasks', 'edit', $task)) {
            throw new HttpException(403);
        }
        $items = [];
        foreach (array_slice((array) ($r->json()['items'] ?? []), 0, 100) as $it) {
            $t = trim(mb_substr((string) ($it['t'] ?? ''), 0, 300));
            if ($t !== '') {
                $items[] = ['t' => $t, 'd' => !empty($it['d'])];
            }
        }
        $done = count(array_filter($items, fn ($i) => $i['d']));
        $data = ['checklist' => $items ? json_encode($items, JSON_UNESCAPED_UNICODE) : null, 'updated_at' => DB::now()];
        if ($items && $task['status'] !== 'Done') {
            $data['progress'] = (int) round($done / count($items) * 100);
        }
        App::db()->update('tasks', $data, ['id' => (int) $id]);
        return $this->json(['ok' => true, 'done' => $done, 'total' => count($items), 'progress' => $data['progress'] ?? (int) $task['progress']]);
    }
}

<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ActivityService;

final class ActivityController extends Controller
{
    public function store(Request $r, string $entity, string $id): Response
    {
        $svc = $this->service($entity);
        $svc->find((int) $id); // view access is enough to add notes/log activity
        $body = trim($r->str('body'));
        $type = $r->str('type', 'note');
        if ($body === '') {
            throw new HttpException(422, 'Write something first.', ['body' => 'Required']);
        }
        $occurred = $r->str('occurred_at') !== '' ? to_utc($r->str('occurred_at')) : null;
        $aid = ActivityService::log($entity, (int) $id, $type === 'system' ? 'note' : $type, $body, $r->str('subject') ?: null, [], $occurred);
        if (in_array($entity, ['contacts'], true) && in_array($type, ['call', 'meeting', 'email', 'whatsapp'], true)) {
            App::db()->update('contacts', ['updated_at' => DB::now()], ['id' => (int) $id]);
        }
        $act = ActivityService::forRecord($entity, (int) $id, 1);
        return $this->json(['ok' => true, 'message' => 'Added to timeline', 'html' => View::partial('records/_activity_item', ['a' => $act[0] ?? null])]);
    }

    public function destroy(Request $r, string $id): Response
    {
        $a = App::db()->one('SELECT * FROM activities WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [(int) $id, Auth::companyId()]);
        if (!$a || $a['type'] === 'system') {
            throw new HttpException(404);
        }
        if ((int) $a['user_id'] !== Auth::id() && empty(Auth::user()['is_admin'])) {
            throw new HttpException(403);
        }
        App::db()->update('activities', ['deleted_at' => DB::now()], ['id' => (int) $id]);
        return $this->json(['ok' => true, 'message' => 'Removed']);
    }
}

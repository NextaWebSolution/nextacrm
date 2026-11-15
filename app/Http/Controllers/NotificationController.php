<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;

final class NotificationController extends Controller
{
    public function index(Request $r): Response
    {
        $items = array_map(fn ($n) => ['id' => (int) $n['id'], 'title' => $n['title'], 'body' => $n['body'], 'url' => $n['url'] ? url($n['url']) : null, 'read' => $n['read_at'] !== null, 'ago' => time_ago($n['created_at']), 'type' => $n['type']], NotificationService::latest(20));
        return $this->json(['unread' => NotificationService::unreadCount(), 'items' => $items]);
    }

    public function read(Request $r): Response
    {
        $id = $r->int('id');
        $sql = 'UPDATE notifications SET read_at = ? WHERE user_id = ? AND company_id = ? AND read_at IS NULL';
        $params = [DB::now(), Auth::id(), Auth::companyId()];
        if ($id) {
            $sql .= ' AND id = ?';
            $params[] = $id;
        }
        App::db()->query($sql, $params);
        return $this->json(['ok' => true, 'unread' => NotificationService::unreadCount()]);
    }

    public function page(Request $r): Response
    {
        return $this->view('notifications', ['title' => 'Notifications', 'items' => NotificationService::latest(100)]);
    }
}

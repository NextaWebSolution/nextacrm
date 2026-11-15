<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Http\Controllers\Controller;

final class SecurityController extends Controller
{
    public function index(Request $r): Response
    {
        $failed = App::db()->all('SELECT email, ip, created_at FROM login_attempts WHERE success = 0 ORDER BY id DESC LIMIT 15');
        return $this->view('settings/security', ['title' => 'Security', 'tab' => 'security', 'failed' => $failed]);
    }

    public function save(Request $r): Response
    {
        $ext = array_filter(array_map(fn ($e) => strtolower(trim($e, " .\t")), explode(',', $r->str('allowed_upload_ext'))), fn ($e) => preg_match('/^[a-z0-9]{1,10}$/', $e) && !in_array($e, \App\Services\FileService::BLOCKED_EXT, true));
        Settings::setMany([
            'security.password_min' => max(8, min(64, $r->int('password_min', 10))),
            'security.idle_timeout' => max(5, min(1440, $r->int('idle_timeout', 120))),
            'security.session_lifetime' => max(60, min(43200, $r->int('session_lifetime', 720))),
            'security.enforce_2fa_admins' => (bool) $r->input('enforce_2fa_admins'),
            'security.max_upload_mb' => max(1, min(512, $r->int('max_upload_mb', 25))),
            'security.allowed_upload_ext' => implode(',', array_unique($ext)),
            'data.purge_days' => max(7, min(3650, $r->int('purge_days', 90))),
        ]);
        Audit::log('settings.security');
        return $this->back('success', 'Security settings saved.');
    }

    public function audit(Request $r): Response
    {
        $db = App::db();
        $where = ['(a.company_id = ? OR a.company_id IS NULL)'];
        $params = [Auth::companyId()];
        if (($action = (string) ($r->query['action'] ?? '')) !== '') {
            $where[] = 'a.action LIKE ?' . $db->likeEscape();
            $params[] = DB::like($action);
        }
        if (($uid = (int) ($r->query['user'] ?? 0)) > 0) {
            $where[] = 'a.user_id = ?';
            $params[] = $uid;
        }
        $page = max(1, (int) ($r->query['page'] ?? 1));
        $total = (int) $db->value('SELECT COUNT(*) FROM audit_logs a WHERE ' . implode(' AND ', $where), $params);
        $rows = $db->all('SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50), $params);
        return $this->view('settings/audit', ['title' => 'Audit log', 'tab' => 'audit', 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 50)), 'users' => \App\Services\LookupService::companyUsers()]);
    }
}

<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\AttendanceService as A;

final class AttendanceController extends Controller
{
    public function index(Request $r): Response
    {
        $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($r->query['month'] ?? '')) ? $r->query['month'] : date('Y-m');
        $me = A::myEmployee();
        $level = Permissions::level('attendance', 'view');
        $reg = null;
        if ($level !== 'none') {
            $reg = A::month($ym);
            if ($level === 'own') {
                $reg['rows'] = array_values(array_filter($reg['rows'], fn ($x) => $me && (int) $x['employee']['id'] === (int) $me['id']));
            }
        }
        return $this->view('attendance/index', [
            'title' => 'Attendance', 'ym' => $ym, 'me' => $me, 'today' => $me ? A::todayRow((int) $me['id']) : null, 'reg' => $reg,
            'canMark' => can('attendance', 'edit'), 'canManage' => can('attendance', 'manage'),
            'holidayList' => App::db()->all('SELECT * FROM holidays WHERE company_id = ? AND date >= ? ORDER BY date LIMIT 60', [Auth::companyId(), date('Y-01-01')]),
        ]);
    }

    public function checkIn(Request $r): Response
    {
        return $this->json(['ok' => true, 'message' => A::checkIn(), 'reload' => true]);
    }

    public function checkOut(Request $r): Response
    {
        return $this->json(['ok' => true, 'message' => A::checkOut(), 'reload' => true]);
    }

    public function mark(Request $r): Response
    {
        Permissions::authorize('attendance', 'edit');
        A::mark($r->int('employee_id'), $r->str('date'), $r->str('status'), $r->str('note') ?: null);
        return $this->json(['ok' => true, 'message' => 'Saved.']);
    }

    public function holiday(Request $r): Response
    {
        Permissions::authorize('attendance', 'manage');
        $date = $r->str('date');
        $name = mb_substr(trim($r->str('name')), 0, 150);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '') {
            throw new HttpException(422, 'Enter a date and a name.');
        }
        $db = App::db();
        $db->query('DELETE FROM holidays WHERE company_id = ? AND date = ?', [Auth::companyId(), $date]);
        $db->insert('holidays', ['company_id' => Auth::companyId(), 'date' => $date, 'name' => $name]);
        return $this->json(['ok' => true, 'message' => 'Holiday added.', 'reload' => true]);
    }

    public function deleteHoliday(Request $r, string $id): Response
    {
        Permissions::authorize('attendance', 'manage');
        App::db()->query('DELETE FROM holidays WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        return $this->json(['ok' => true, 'message' => 'Holiday removed.', 'reload' => true]);
    }

    public function settings(Request $r): Response
    {
        Permissions::authorize('attendance', 'manage');
        $off = array_values(array_filter(array_map('intval', (array) $r->input('weekly_off', [])), fn ($d) => $d >= 1 && $d <= 7));
        Settings::setMany(['hr.weekly_off' => $off, 'hr.half_day_minutes' => max(60, min(600, $r->int('half_day_minutes', 240))), 'hr.unmarked_absent' => (bool) $r->input('unmarked_absent')], Auth::companyId());
        return $this->json(['ok' => true, 'message' => 'Attendance settings saved.', 'reload' => true]);
    }
}

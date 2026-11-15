<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/** Daily attendance: self check-in/out, HR register, holidays, monthly summaries for payroll. */
final class AttendanceService
{
    public const STATUSES = ['Present', 'Half Day', 'Absent', 'Leave', 'Holiday', 'Work From Home'];

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', user_tz()))->format('Y-m-d');
    }

    public static function myEmployee(): ?array
    {
        return App::db()->one("SELECT * FROM employees WHERE user_id = ? AND company_id = ? AND deleted_at IS NULL AND status <> 'Exited' LIMIT 1", [Auth::id(), Auth::companyId()]) ?: null;
    }

    public static function todayRow(int $employeeId): ?array
    {
        return App::db()->one('SELECT * FROM attendance WHERE employee_id = ? AND date = ?', [$employeeId, self::today()]) ?: null;
    }

    public static function checkIn(): string
    {
        $emp = self::myEmployee();
        if (!$emp) {
            throw new HttpException(422, 'Your user is not linked to an employee record. Ask HR to set "User account" on your employee profile.');
        }
        $db = App::db();
        $row = self::todayRow((int) $emp['id']);
        if ($row && $row['check_in']) {
            throw new HttpException(422, 'You have already checked in today.');
        }
        $data = ['check_in' => DB::now(), 'status' => 'Present', 'source' => 'web', 'ip' => App::request()->ip(), 'updated_at' => DB::now()];
        if ($row) {
            $db->update('attendance', $data, ['id' => $row['id']]);
        } else {
            $db->insert('attendance', $data + ['company_id' => Auth::companyId(), 'employee_id' => (int) $emp['id'], 'date' => self::today(), 'created_by' => Auth::id(), 'created_at' => DB::now()]);
        }
        return 'Checked in at ' . (new \DateTimeImmutable('now', user_tz()))->format('H:i') . '.';
    }

    public static function checkOut(): string
    {
        $emp = self::myEmployee();
        $row = $emp ? self::todayRow((int) $emp['id']) : null;
        if (!$row || !$row['check_in']) {
            throw new HttpException(422, 'Check in first.');
        }
        if ($row['check_out']) {
            throw new HttpException(422, 'You have already checked out today.');
        }
        $minutes = max(0, (int) round((time() - strtotime($row['check_in'] . ' UTC')) / 60));
        $half = (int) setting('hr.half_day_minutes', 240);
        $status = $row['status'] === 'Work From Home' ? 'Work From Home' : ($minutes < $half ? 'Half Day' : 'Present');
        App::db()->update('attendance', ['check_out' => DB::now(), 'minutes' => $minutes, 'status' => $status, 'updated_at' => DB::now()], ['id' => $row['id']]);
        return 'Checked out · ' . intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm today.';
    }

    /** HR edit of a register cell. */
    public static function mark(int $employeeId, string $date, string $status, ?string $note = null): void
    {
        if (!in_array($status, array_merge(self::STATUSES, ['']), true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new HttpException(422, 'Invalid attendance value.');
        }
        $db = App::db();
        if (!$db->value('SELECT id FROM employees WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [$employeeId, Auth::companyId()])) {
            throw new HttpException(404);
        }
        $row = $db->one('SELECT id FROM attendance WHERE employee_id = ? AND date = ?', [$employeeId, $date]);
        if ($status === '') {
            if ($row) {
                $db->delete('attendance', ['id' => $row['id']]);
            }
            return;
        }
        $data = ['status' => $status, 'source' => 'manual', 'note' => $note ? mb_substr($note, 0, 500) : null, 'updated_at' => DB::now()];
        if ($row) {
            $db->update('attendance', $data, ['id' => $row['id']]);
        } else {
            $db->insert('attendance', $data + ['company_id' => Auth::companyId(), 'employee_id' => $employeeId, 'date' => $date, 'created_by' => Auth::id(), 'created_at' => DB::now()]);
        }
    }

    public static function weeklyOff(): array
    {
        $v = setting('hr.weekly_off', [7]);
        return array_map('intval', is_array($v) ? $v : [7]);
    }

    public static function holidays(string $from, string $to): array
    {
        $out = [];
        foreach (App::db()->all('SELECT date, name FROM holidays WHERE company_id = ? AND date BETWEEN ? AND ?', [Auth::companyId(), $from, $to]) as $h) {
            $out[$h['date']] = $h['name'];
        }
        return $out;
    }

    /**
     * Register for a month: employees × days with a code per cell, plus per-employee totals.
     * Codes: P present, HD half day, A absent, L paid leave, U unpaid leave, H holiday, W weekly off, WFH, '' future/unknown.
     */
    public static function month(string $ym): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            throw new HttpException(422, 'Invalid month.');
        }
        $db = App::db();
        $first = $ym . '-01';
        $last = date('Y-m-t', strtotime($first));
        $today = self::today();
        $emps = $db->all("SELECT id, name, employee_code, joining_date, exit_date, salary FROM employees WHERE company_id = ? AND deleted_at IS NULL AND (joining_date IS NULL OR joining_date <= ?) AND (exit_date IS NULL OR exit_date >= ?) ORDER BY name", [Auth::companyId(), $last, $first]);
        $att = [];
        foreach ($db->all('SELECT employee_id, date, status, minutes, check_in, check_out FROM attendance WHERE company_id = ? AND date BETWEEN ? AND ?', [Auth::companyId(), $first, $last]) as $a) {
            $att[(int) $a['employee_id']][$a['date']] = $a;
        }
        $leave = [];
        foreach ($db->all("SELECT employee_id, type, start_date, end_date FROM leave_requests WHERE company_id = ? AND deleted_at IS NULL AND status = 'Approved' AND start_date <= ? AND end_date >= ?", [Auth::companyId(), $last, $first]) as $l) {
            for ($d = max($l['start_date'], $first); $d <= min($l['end_date'], $last); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $leave[(int) $l['employee_id']][$d] = $l['type'];
            }
        }
        $hol = self::holidays($first, $last);
        $off = self::weeklyOff();
        $days = [];
        for ($d = $first; $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $days[] = $d;
        }
        $rows = [];
        foreach ($emps as $e) {
            $cells = [];
            $t = ['present' => 0.0, 'leave' => 0.0, 'unpaid' => 0.0, 'absent' => 0.0, 'working' => 0, 'employed' => 0];
            $tracked = isset($att[(int) $e['id']]);
            $strict = (bool) setting('hr.unmarked_absent', false);
            foreach ($days as $d) {
                $employed = (!$e['joining_date'] || $d >= $e['joining_date']) && (!$e['exit_date'] || $d <= $e['exit_date']);
                $isOff = in_array((int) date('N', strtotime($d)), $off, true);
                $a = $att[(int) $e['id']][$d] ?? null;
                $lv = $leave[(int) $e['id']][$d] ?? null;
                $code = '';
                if (!$employed) {
                    $code = '–';
                } elseif ($a) {
                    $code = ['Present' => 'P', 'Half Day' => 'HD', 'Absent' => 'A', 'Leave' => 'L', 'Holiday' => 'H', 'Work From Home' => 'WFH'][$a['status']] ?? 'P';
                } elseif ($lv) {
                    $code = $lv === 'Unpaid' ? 'U' : ($lv === 'Work From Home' ? 'WFH' : 'L');
                } elseif (isset($hol[$d])) {
                    $code = 'H';
                } elseif ($isOff) {
                    $code = 'W';
                } elseif ($d < $today && $tracked && $strict) {
                    $code = 'A';
                }
                if ($employed) {
                    $t['employed']++;
                    if (!$isOff && !isset($hol[$d])) {
                        $t['working']++;
                    }
                }
                match ($code) {
                    'P', 'WFH' => $t['present'] += 1,
                    'HD' => [$t['present'] += 0.5, $t['absent'] += 0.5],
                    'L' => $t['leave'] += 1,
                    'U' => $t['unpaid'] += 1,
                    'A' => $t['absent'] += 1,
                    default => null,
                };
                $cells[$d] = ['code' => $code, 'minutes' => (int) ($a['minutes'] ?? 0)];
            }
            $t['lop'] = $t['absent'] + $t['unpaid'];
            $t['tracked'] = $tracked;
            $rows[] = ['employee' => $e, 'cells' => $cells, 'totals' => $t];
        }
        return ['days' => $days, 'rows' => $rows, 'holidays' => $hol, 'off' => $off];
    }
}

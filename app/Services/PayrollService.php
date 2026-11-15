<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Mailer;

/**
 * Monthly payroll with Indian statutory deductions (all rates configurable in Settings → Payroll):
 *  - Salary on the employee record = monthly gross (CTC). Basic = basic% of gross, HRA = HRA% of basic, Special = rest.
 *  - Loss of pay from attendance (absent + unpaid leave + half days) and joining/exit proration.
 *  - PF (employee) = PF% × min(prorated Basic, PF wage ceiling); ESI (employee) = ESI% × gross when full gross ≤ threshold;
 *    Professional tax = fixed monthly amount; TDS and other deductions are entered per payslip while the run is a draft.
 * Draft → Approved (locked, needs payroll "approve") → Paid (records one salary expense for the month).
 */
final class PayrollService
{
    public static function cfg(): array
    {
        return [
            'basic_pct' => (float) setting('payroll.basic_pct', 50),
            'hra_pct' => (float) setting('payroll.hra_pct', 40),
            'pf_rate' => (float) setting('payroll.pf_rate', 12),
            'pf_cap' => (float) setting('payroll.pf_wage_cap', 15000),
            'esi_rate' => (float) setting('payroll.esi_rate', 0.75),
            'esi_threshold' => (float) setting('payroll.esi_threshold', 21000),
            'pt' => (float) setting('payroll.pt_amount', 200),
            'basis' => (string) setting('payroll.days_basis', 'calendar'),
        ];
    }

    /** Computes one employee's payslip figures for a month (pure calculation, used by tests too). */
    public static function compute(array $emp, array $totals, int $daysInMonth, array $c, float $tds = 0, float $other = 0): array
    {
        $salary = max(0, (float) $emp['salary']);
        $base = $c['basis'] === 'working' ? max(1, (int) $totals['working']) : $daysInMonth;
        $employed = $c['basis'] === 'working' ? (int) $totals['working'] : (int) $totals['employed'];
        $lop = min((float) $totals['lop'], (float) $employed);
        $paid = max(0.0, $employed - $lop);
        $gross = round($salary * $paid / $base, 2);
        $basic = round($gross * $c['basic_pct'] / 100, 2);
        $hra = round($basic * $c['hra_pct'] / 100, 2);
        $special = round($gross - $basic - $hra, 2);
        $pf = !empty($emp['pf_applicable']) ? round(min($basic, $c['pf_cap']) * $c['pf_rate'] / 100) : 0.0;
        $esi = !empty($emp['esi_applicable']) && $salary <= $c['esi_threshold'] && $gross > 0 ? ceil($gross * $c['esi_rate'] / 100) : 0.0;
        $pt = !empty($emp['pt_applicable']) && $gross > 0 ? $c['pt'] : 0.0;
        $tds = max(0, round($tds, 2));
        $other = max(0, round($other, 2));
        $net = round($gross - $pf - $esi - $pt - $tds - $other, 2);
        return ['working_days' => $base, 'paid_days' => $paid, 'lop_days' => $lop, 'gross' => $gross, 'basic' => $basic, 'hra' => $hra, 'special' => $special,
            'pf' => $pf, 'esi' => $esi, 'pt' => $pt, 'tds' => $tds, 'other_deductions' => $other, 'net' => max(0, $net)];
    }

    public static function find(int $id): array
    {
        $run = App::db()->one('SELECT * FROM payroll_runs WHERE id = ? AND company_id = ?', [$id, Auth::companyId()]);
        if (!$run) {
            throw new HttpException(404);
        }
        return $run;
    }

    /** Creates (or recalculates a draft) payroll run for YYYY-MM. */
    public static function generate(string $ym): int
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new HttpException(422, 'Choose a valid month.');
        }
        if ($ym > date('Y-m')) {
            throw new HttpException(422, 'You can only run payroll for the current or a past month.');
        }
        $db = App::db();
        $run = $db->one('SELECT * FROM payroll_runs WHERE company_id = ? AND month = ?', [Auth::companyId(), $ym]);
        if ($run && $run['status'] !== 'Draft') {
            throw new HttpException(422, 'Payroll for this month is already ' . strtolower($run['status']) . '.');
        }
        $c = self::cfg();
        $reg = AttendanceService::month($ym);
        $dim = (int) date('t', strtotime($ym . '-01'));
        return $db->transaction(function () use ($db, $run, $ym, $c, $reg, $dim) {
            $runId = $run ? (int) $run['id'] : $db->insert('payroll_runs', ['company_id' => Auth::companyId(), 'month' => $ym, 'status' => 'Draft', 'created_by' => Auth::id(), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
            $keep = [];
            foreach ($db->all('SELECT employee_id, tds, other_deductions FROM payslips WHERE run_id = ?', [$runId]) as $p) {
                $keep[(int) $p['employee_id']] = $p;
            }
            $db->delete('payslips', ['run_id' => $runId]);
            $tot = ['gross' => 0.0, 'ded' => 0.0, 'net' => 0.0];
            foreach ($reg['rows'] as $row) {
                $emp = $db->find('employees', (int) $row['employee']['id']);
                if (!$emp || (float) $emp['salary'] <= 0) {
                    continue;
                }
                $k = $keep[(int) $emp['id']] ?? ['tds' => 0, 'other_deductions' => 0];
                $p = self::compute($emp, $row['totals'], $dim, $c, (float) $k['tds'], (float) $k['other_deductions']);
                $db->insert('payslips', $p + ['company_id' => Auth::companyId(), 'run_id' => $runId, 'employee_id' => (int) $emp['id'], 'components' => json_encode(['config' => $c, 'attendance_tracked' => $row['totals']['tracked']]), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
                $tot['gross'] += $p['gross'];
                $tot['ded'] += $p['pf'] + $p['esi'] + $p['pt'] + $p['tds'] + $p['other_deductions'];
                $tot['net'] += $p['net'];
            }
            $db->update('payroll_runs', ['total_gross' => round($tot['gross'], 2), 'total_deductions' => round($tot['ded'], 2), 'total_net' => round($tot['net'], 2), 'updated_at' => DB::now()], ['id' => $runId]);
            Audit::log('payroll.generated', null, $runId, ['month' => $ym]);
            return $runId;
        });
    }

    public static function adjust(int $payslipId, float $tds, float $other): void
    {
        $db = App::db();
        $p = $db->one('SELECT p.*, r.status AS run_status, r.month FROM payslips p JOIN payroll_runs r ON r.id = p.run_id WHERE p.id = ? AND p.company_id = ?', [$payslipId, Auth::companyId()]);
        if (!$p) {
            throw new HttpException(404);
        }
        if ($p['run_status'] !== 'Draft') {
            throw new HttpException(422, 'Approved payroll cannot be changed.');
        }
        $db->update('payslips', ['tds' => max(0, round($tds, 2)), 'other_deductions' => max(0, round($other, 2))], ['id' => $payslipId]);
        self::generate($p['month']);
    }

    public static function approve(int $runId): void
    {
        $run = self::find($runId);
        if ($run['status'] !== 'Draft') {
            throw new HttpException(422, 'Only a draft can be approved.');
        }
        App::db()->update('payroll_runs', ['status' => 'Approved', 'approved_by' => Auth::id(), 'approved_at' => DB::now(), 'updated_at' => DB::now()], ['id' => $runId]);
        Audit::log('payroll.approved', null, $runId, ['month' => $run['month'], 'net' => $run['total_net']]);
    }

    public static function markPaid(int $runId): void
    {
        $db = App::db();
        $run = self::find($runId);
        if ($run['status'] !== 'Approved') {
            throw new HttpException(422, 'Approve the payroll before marking it paid.');
        }
        $db->transaction(function () use ($db, $run) {
            $changed = $db->query("UPDATE payroll_runs SET status = 'Paid', paid_at = ?, updated_at = ? WHERE id = ? AND status = 'Approved'", [DB::now(), DB::now(), $run['id']])->rowCount();
            if ($changed !== 1) {
                throw new HttpException(422, 'Already marked as paid.');
            }
            $db->insert('expenses', [
                'company_id' => Auth::companyId(), 'title' => 'Salaries ' . date('F Y', strtotime($run['month'] . '-01')), 'category' => 'Salaries',
                'amount' => $run['total_gross'], 'tax_amount' => 0, 'currency' => CompanyService::currentCurrency(), 'expense_date' => date('Y-m-d'),
                'payment_method' => 'Bank Transfer', 'status' => 'Approved', 'approved_by' => Auth::id(), 'approved_at' => DB::now(), 'approval_status' => 'approved',
                'notes' => 'Recorded automatically from payroll run #' . $run['id'] . '. Net paid ' . number_format((float) $run['total_net'], 2) . ', deductions ' . number_format((float) $run['total_deductions'], 2) . '.',
                'assigned_to' => Auth::id(), 'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => DB::now(), 'updated_at' => DB::now(),
            ]);
        });
        Audit::log('payroll.paid', null, (int) $run['id'], ['month' => $run['month']]);
    }

    public static function payslips(int $runId): array
    {
        return App::db()->all('SELECT p.*, e.name, e.employee_code, e.designation, e.email, e.pan, e.uan, e.bank_account FROM payslips p JOIN employees e ON e.id = p.employee_id WHERE p.run_id = ? AND p.company_id = ? ORDER BY e.name', [$runId, Auth::companyId()]);
    }

    public static function payslipPdf(array $slip, array $run): string
    {
        $html = \App\Core\View::partial('payroll/payslip_pdf', ['s' => $slip, 'run' => $run, 'company' => CompanyService::current()]);
        return SalesService::renderPdf($html);
    }

    /** E-mails each employee their payslip PDF (approved or paid runs). */
    public static function emailPayslips(int $runId): int
    {
        $run = self::find($runId);
        if ($run['status'] === 'Draft') {
            throw new HttpException(422, 'Approve the payroll before sending payslips.');
        }
        $n = 0;
        foreach (self::payslips($runId) as $s) {
            if (!filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $pdf = self::payslipPdf($s, $run);
            $month = date('F Y', strtotime($run['month'] . '-01'));
            Mailer::queue($s['email'], 'Payslip for ' . $month, '<p>Hi ' . e($s['name']) . ',</p><p>Your payslip for ' . e($month) . ' is attached.</p>', [['name' => 'payslip-' . $run['month'] . '.pdf', 'mime' => 'application/pdf', 'content_b64' => base64_encode($pdf)]]);
            $n++;
        }
        return $n;
    }
}

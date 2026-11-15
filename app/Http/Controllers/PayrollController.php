<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Services\AttendanceService;
use App\Services\PayrollService as P;

final class PayrollController extends Controller
{
    public function index(Request $r): Response
    {
        Permissions::authorize('payroll', 'view');
        return $this->view('payroll/index', ['title' => 'Payroll', 'runs' => App::db()->all('SELECT * FROM payroll_runs WHERE company_id = ? ORDER BY month DESC', [Auth::companyId()]), 'cfg' => P::cfg()]);
    }

    public function generate(Request $r): Response
    {
        Permissions::authorize('payroll', 'create');
        $id = P::generate($r->str('month'));
        return $this->json(['ok' => true, 'message' => 'Payroll calculated.', 'redirect' => url('/payroll/' . $id)]);
    }

    public function show(Request $r, string $id): Response
    {
        Permissions::authorize('payroll', 'view');
        $run = P::find((int) $id);
        View::share('breadcrumbs', ['Payroll' => url('/payroll')]);
        return $this->view('payroll/run', ['title' => 'Payroll ' . date('F Y', strtotime($run['month'] . '-01')), 'run' => $run, 'slips' => P::payslips((int) $id)]);
    }

    public function adjust(Request $r, string $id): Response
    {
        Permissions::authorize('payroll', 'edit');
        P::adjust((int) $id, (float) $r->input('tds', 0), (float) $r->input('other_deductions', 0));
        return $this->json(['ok' => true, 'message' => 'Payslip updated.', 'reload' => true]);
    }

    public function recalc(Request $r, string $id): Response
    {
        Permissions::authorize('payroll', 'edit');
        P::generate(P::find((int) $id)['month']);
        return $this->json(['ok' => true, 'message' => 'Recalculated from the latest salaries and attendance.', 'reload' => true]);
    }

    public function approve(Request $r, string $id): Response
    {
        Permissions::authorize('payroll', 'approve');
        P::approve((int) $id);
        return $this->json(['ok' => true, 'message' => 'Payroll approved and locked.', 'reload' => true]);
    }

    public function paid(Request $r, string $id): Response
    {
        Permissions::authorize('payroll', 'approve');
        P::markPaid((int) $id);
        return $this->json(['ok' => true, 'message' => 'Marked as paid. A salary expense has been recorded.', 'reload' => true]);
    }

    public function email(Request $r, string $id): Response
    {
        Permissions::authorize('payroll', 'approve');
        $n = P::emailPayslips((int) $id);
        return $this->json(['ok' => true, 'message' => "$n payslip(s) queued for e-mail."]);
    }

    /** Payslip PDF: payroll viewers, or the employee themself for approved/paid runs. */
    public function payslip(Request $r, string $id): Response
    {
        $db = App::db();
        $s = $db->one('SELECT p.*, e.name, e.employee_code, e.designation, e.email, e.pan, e.uan, e.bank_account, e.user_id FROM payslips p JOIN employees e ON e.id = p.employee_id WHERE p.id = ? AND p.company_id = ?', [(int) $id, Auth::companyId()]);
        if (!$s) {
            throw new HttpException(404);
        }
        $run = P::find((int) $s['run_id']);
        $own = (int) $s['user_id'] === Auth::id() && $run['status'] !== 'Draft';
        if (!$own && !can('payroll', 'view')) {
            throw new HttpException(403);
        }
        $pdf = P::payslipPdf($s, $run);
        return Response::download($pdf, 'payslip-' . $run['month'] . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $s['name']) . '.pdf', str_starts_with($pdf, '%PDF') ? 'application/pdf' : 'text/html');
    }

    public function mine(Request $r): Response
    {
        $me = AttendanceService::myEmployee();
        $slips = $me ? App::db()->all("SELECT p.id, p.net, p.gross, r.month, r.status FROM payslips p JOIN payroll_runs r ON r.id = p.run_id WHERE p.employee_id = ? AND p.company_id = ? AND r.status IN ('Approved', 'Paid') ORDER BY r.month DESC", [(int) $me['id'], Auth::companyId()]) : [];
        return $this->view('payroll/mine', ['title' => 'My payslips', 'me' => $me, 'slips' => $slips]);
    }

    public function settings(Request $r): Response
    {
        Permissions::authorize('payroll', 'manage');
        $f = fn (string $k, float $min, float $max, float $def) => max($min, min($max, (float) $r->input($k, $def)));
        Settings::setMany([
            'payroll.basic_pct' => $f('basic_pct', 10, 100, 50), 'payroll.hra_pct' => $f('hra_pct', 0, 100, 40),
            'payroll.pf_rate' => $f('pf_rate', 0, 25, 12), 'payroll.pf_wage_cap' => $f('pf_wage_cap', 0, 1e7, 15000),
            'payroll.esi_rate' => $f('esi_rate', 0, 10, 0.75), 'payroll.esi_threshold' => $f('esi_threshold', 0, 1e7, 21000),
            'payroll.pt_amount' => $f('pt_amount', 0, 10000, 200), 'payroll.days_basis' => $r->str('days_basis') === 'working' ? 'working' : 'calendar',
        ], Auth::companyId());
        return $this->json(['ok' => true, 'message' => 'Payroll settings saved.', 'reload' => true]);
    }
}

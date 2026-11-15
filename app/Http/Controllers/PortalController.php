<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\FileService;
use App\Services\NotificationService;
use App\Services\Payments\PaymentService;
use App\Services\PortalService;
use App\Services\RecordService;
use App\Services\SalesService;

/** Customer-facing portal. All data access goes through PortalService's customer-scoped queries. */
final class PortalController extends Controller
{
    private function page(string $view, array $data, array $c): Response
    {
        return Response::html(View::render('portal/' . $view, $data + ['contact' => $c, 'company' => $c['company'], 'flash' => Session::takeFlash()], 'portal'));
    }

    /** Runs $fn with the company's settings/currency context (no staff permissions are used for data access). */
    private function inCompany(array $c, callable $fn): mixed
    {
        return Auth::actingAs(0, (int) $c['company_id'], $fn);
    }

    // ── Auth ──
    public function loginForm(Request $r): Response
    {
        if (PortalService::current()) {
            return Response::redirect('/portal');
        }
        return Response::html(View::render('portal/login', ['flash' => Session::takeFlash()], 'portal_guest'));
    }

    public function login(Request $r): Response
    {
        $res = PortalService::login($r->str('email'), (string) $r->input('password', ''));
        if (!$res['ok']) {
            Session::flash('error', $res['message']);
            return Response::redirect('/portal/login');
        }
        return Response::redirect('/portal');
    }

    public function logout(Request $r): Response
    {
        PortalService::logout();
        return Response::redirect('/portal/login');
    }

    public function forgotForm(Request $r): Response
    {
        return Response::html(View::render('portal/forgot', ['flash' => Session::takeFlash()], 'portal_guest'));
    }

    public function forgot(Request $r): Response
    {
        PortalService::forgot($r->str('email'));
        Session::flash('success', 'If that address has portal access, we have e-mailed a reset link.');
        return Response::redirect('/portal/login');
    }

    public function activateForm(Request $r, string $token): Response
    {
        $row = PortalService::findToken($token);
        if (!$row) {
            Session::flash('error', 'This link is invalid or has expired.');
            return Response::redirect('/portal/login');
        }
        return Response::html(View::render('portal/activate', ['token' => $token, 'row' => $row, 'flash' => Session::takeFlash()], 'portal_guest'));
    }

    public function activate(Request $r, string $token): Response
    {
        $row = PortalService::findToken($token);
        if (!$row) {
            Session::flash('error', 'This link is invalid or has expired.');
            return Response::redirect('/portal/login');
        }
        $pw = (string) $r->input('password', '');
        if ($pw !== (string) $r->input('password_confirmation', '')) {
            Session::flash('error', 'The passwords do not match.');
            return Response::redirect('/portal/activate/' . $token);
        }
        if ($err = PortalService::setPassword($row, $pw)) {
            Session::flash('error', $err);
            return Response::redirect('/portal/activate/' . $token);
        }
        Session::flash('success', 'Your password is set. Sign in to continue.');
        return Response::redirect('/portal/login');
    }

    // ── Pages ──
    public function home(Request $r): Response
    {
        $c = PortalService::require();
        $inv = PortalService::invoices($c);
        $due = array_filter($inv, fn ($i) => in_array($i['status'], PaymentService::PAYABLE, true) && (float) $i['balance'] > 0);
        return $this->page('home', [
            'title' => 'Overview', 'due' => $due, 'invoices' => array_slice($inv, 0, 5),
            'quotes' => array_filter(PortalService::quotes($c), fn ($q) => $q['status'] === 'Sent'),
            'projects' => PortalService::projects($c),
            'tickets' => array_slice(array_filter(PortalService::tickets($c), fn ($t) => !in_array($t['status'], ['Resolved', 'Closed'], true)), 0, 5),
        ], $c);
    }

    public function invoices(Request $r): Response
    {
        $c = PortalService::require();
        return $this->page('invoices', ['title' => 'Invoices', 'invoices' => PortalService::invoices($c)], $c);
    }

    public function invoice(Request $r, string $id): Response
    {
        $c = PortalService::require();
        $inv = PortalService::invoice($c, (int) $id);
        [$doc, $gateways] = $this->inCompany($c, fn () => [SalesService::documentData('invoice', $inv), PaymentService::enabled()]);
        return $this->page('invoice', ['title' => 'Invoice ' . $inv['number'], 'inv' => $inv, 'doc' => $doc, 'gateways' => $gateways], $c);
    }

    public function invoicePdf(Request $r, string $id): Response
    {
        $c = PortalService::require();
        $inv = PortalService::invoice($c, (int) $id);
        $pdf = $this->inCompany($c, fn () => SalesService::pdf('invoice', $inv));
        return Response::download($pdf, $inv['number'] . '.pdf', str_starts_with($pdf, '%PDF') ? 'application/pdf' : 'text/html');
    }

    public function pay(Request $r, string $id): Response
    {
        $c = PortalService::require();
        $inv = PortalService::invoice($c, (int) $id);
        return (new PaymentController())->startFor($inv, $r->str('gateway'), 'portal', '/portal/invoices/' . $inv['id']);
    }

    public function quotes(Request $r): Response
    {
        $c = PortalService::require();
        return $this->page('quotes', ['title' => 'Quotations', 'quotes' => PortalService::quotes($c)], $c);
    }

    public function projects(Request $r): Response
    {
        $c = PortalService::require();
        return $this->page('projects', ['title' => 'Projects', 'projects' => PortalService::projects($c)], $c);
    }

    public function project(Request $r, string $id): Response
    {
        $c = PortalService::require();
        $p = PortalService::project($c, (int) $id);
        $db = App::db();
        $milestones = $db->all('SELECT name, due_date, status FROM milestones WHERE project_id = ? AND deleted_at IS NULL ORDER BY due_date, id', [$p['id']]);
        $counts = $db->one("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) AS done FROM tasks WHERE project_id = ? AND deleted_at IS NULL", [$p['id']]);
        return $this->page('project', ['title' => $p['name'], 'p' => $p, 'milestones' => $milestones, 'counts' => $counts], $c);
    }

    public function tickets(Request $r): Response
    {
        $c = PortalService::require();
        return $this->page('tickets', ['title' => 'Support', 'tickets' => PortalService::tickets($c)], $c);
    }

    public function ticket(Request $r, string $id): Response
    {
        $c = PortalService::require();
        $t = PortalService::ticket($c, (int) $id);
        $replies = App::db()->all('SELECT author_name, body, created_at, user_id FROM ticket_replies WHERE ticket_id = ? AND is_internal = 0 ORDER BY id', [$t['id']]);
        return $this->page('ticket', ['title' => $t['number'] . ' · ' . $t['subject'], 't' => $t, 'replies' => $replies], $c);
    }

    public function createTicket(Request $r): Response
    {
        $c = PortalService::require();
        $subject = mb_substr(trim($r->str('subject')), 0, 190);
        $body = mb_substr(trim($r->str('description')), 0, 20000);
        if ($subject === '' || $body === '') {
            Session::flash('error', 'Please add a subject and a description.');
            return Response::redirect('/portal/tickets');
        }
        $priority = in_array($r->str('priority'), ['Low', 'Medium', 'High', 'Urgent'], true) ? $r->str('priority') : 'Medium';
        $ticket = $this->inCompany($c, fn () => RecordService::for('tickets')->create([
            'subject' => $subject, 'description' => $body, 'priority' => $priority, 'channel' => 'Portal',
            'customer_id' => (string) $c['customer_id'], 'contact_id' => (string) $c['id'],
            'requester_name' => $c['name'], 'requester_email' => (string) $c['email'],
        ], ['skip_permission' => true, 'system' => true]));
        Session::flash('success', 'Ticket ' . $ticket['number'] . ' created. We will get back to you soon.');
        return Response::redirect('/portal/tickets/' . $ticket['id']);
    }

    public function reply(Request $r, string $id): Response
    {
        $c = PortalService::require();
        $t = PortalService::ticket($c, (int) $id);
        $body = mb_substr(trim($r->str('body')), 0, 20000);
        if ($body === '') {
            return Response::redirect('/portal/tickets/' . $t['id']);
        }
        $db = App::db();
        $db->insert('ticket_replies', ['ticket_id' => $t['id'], 'user_id' => null, 'body' => $body, 'is_internal' => 0, 'author_name' => $c['name'], 'author_email' => (string) $c['email'], 'created_at' => DB::now()]);
        $status = in_array($t['status'], ['Waiting on Customer', 'Resolved'], true) ? 'Open' : $t['status'];
        $db->update('tickets', ['status' => $status, 'updated_at' => DB::now()], ['id' => $t['id']]);
        if ($t['assigned_to']) {
            NotificationService::notify((int) $t['assigned_to'], 'ticket.reply', 'Customer replied on ' . $t['number'], str_limit($body, 140), '/r/tickets/' . $t['id'], (int) $t['company_id']);
        }
        Session::flash('success', 'Reply sent.');
        return Response::redirect('/portal/tickets/' . $t['id']);
    }

    public function files(Request $r): Response
    {
        $c = PortalService::require();
        return $this->page('files', ['title' => 'Documents', 'files' => PortalService::files($c)], $c);
    }

    public function download(Request $r, string $id): Response
    {
        $c = PortalService::require();
        $f = PortalService::file($c, (int) $id);
        return $this->inCompany($c, fn () => FileService::stream($f));
    }

    // ── Staff actions (contact record) ──
    public function staffInvite(Request $r, string $id): Response
    {
        PortalService::invite((int) $id);
        return $this->json(['ok' => true, 'message' => 'Portal invitation sent.', 'reload' => true]);
    }

    public function staffDisable(Request $r, string $id): Response
    {
        PortalService::disable((int) $id);
        return $this->json(['ok' => true, 'message' => 'Portal access disabled.', 'reload' => true]);
    }

    /** Toggle "visible in client portal" on a file (requires edit access to the parent record). */
    public function shareFile(Request $r, string $id): Response
    {
        $f = App::db()->one('SELECT * FROM files WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [(int) $id, Auth::companyId()]);
        if (!$f) {
            throw new HttpException(404);
        }
        FileService::authorize($f, 'edit');
        $on = $f['is_public'] ? 0 : 1;
        App::db()->update('files', ['is_public' => $on], ['id' => $f['id']]);
        return $this->json(['ok' => true, 'message' => $on ? 'Shared in the client portal.' : 'No longer shared in the portal.', 'reload' => true]);
    }
}

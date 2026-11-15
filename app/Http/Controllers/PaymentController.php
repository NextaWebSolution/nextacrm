<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Payments\PaymentService;

/** Online payment flow: start checkout (public link or portal), browser return, and gateway webhooks. */
final class PaymentController extends Controller
{
    /** POST /p/invoice/{token}/pay */
    public function payPublic(Request $r, string $token): Response
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $token)) {
            throw new HttpException(404);
        }
        $inv = App::db()->one('SELECT * FROM invoices WHERE public_token = ? AND deleted_at IS NULL', [$token]);
        if (!$inv || $inv['status'] === 'Draft') {
            throw new HttpException(404);
        }
        return $this->startFor($inv, $r->str('gateway'), 'public', '/p/invoice/' . $token);
    }

    public function startFor(array $inv, string $gateway, string $source, string $back): Response
    {
        Session::start();
        try {
            $url = Auth::actingAs(0, (int) $inv['company_id'], fn () => PaymentService::start($inv, $gateway, $source));
        } catch (HttpException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect($back);
        }
        if (!$url || !preg_match('#^https://#i', $url)) {
            Session::flash('error', 'The payment could not be started.');
            return Response::redirect($back);
        }
        $res = new Response('', 303);
        $res->headers['Location'] = $url; // external gateway checkout page
        return $res;
    }

    /** GET /pay/return/{gateway}/{attempt} */
    public function returned(Request $r, string $gateway, string $attemptId): Response
    {
        Session::start();
        $db = App::db();
        $a = $db->one('SELECT * FROM payment_attempts WHERE id = ? AND gateway = ?', [(int) $attemptId, $gateway]);
        if (!$a) {
            throw new HttpException(404);
        }
        $inv = $db->find('invoices', (int) $a['invoice_id']);
        $dest = $a['source'] === 'portal' ? '/portal/invoices/' . $inv['id'] : '/p/invoice/' . $inv['public_token'];
        if ($a['status'] === 'paid') {
            Session::flash('success', 'Thank you! Your payment has been received.');
            return Response::redirect($dest);
        }
        try {
            $res = Auth::actingAs(0, (int) $a['company_id'], function () use ($gateway, $a, $r) {
                $gw = PaymentService::gateway($gateway, false);
                $result = $gw?->confirmReturn($a, $r->query);
                return $result ? PaymentService::complete((int) $a['id'], $result) : null;
            });
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::log('Payment return failed: ' . $e->getMessage(), 'payments');
            $res = null;
        }
        if ($res && $res['ok']) {
            Session::flash('success', $res['message']);
        } elseif ($res) {
            Session::flash('error', $res['message']);
        } else {
            Session::flash('error', 'We could not confirm the payment yet. If you completed it, it will appear here shortly.');
        }
        return Response::redirect($dest);
    }

    /** POST /hooks/pay/{gateway}/{company} — called by the gateway, no session or CSRF. */
    public function webhook(Request $r, string $gateway, string $company): Response
    {
        if (!isset(PaymentService::GATEWAYS[$gateway]) || !ctype_digit($company)) {
            throw new HttpException(404);
        }
        $headers = [];
        foreach ($r->server as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        try {
            $res = PaymentService::webhook($gateway, (int) $company, $r->raw(), $headers);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::log("Webhook $gateway rejected: " . $e->getMessage(), 'payments');
            return Response::json(['ok' => false, 'error' => 'rejected'], 400);
        }
        return Response::json(['ok' => true, 'result' => $res['message'] ?? 'ok']);
    }
}

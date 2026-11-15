<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityHeaders;
use App\Core\Session;
use App\Core\View;
use App\Services\ActivityService;
use App\Services\NotificationService;
use App\Services\RecordService;
use App\Services\SalesService;
use App\Services\WorkflowService;

/** Unauthenticated endpoints: shared documents, web-to-lead forms, incoming workflow webhooks. */
final class PublicController extends Controller
{
    private function findDoc(string $type, string $token): array
    {
        if (!in_array($type, ['quote', 'invoice'], true) || !preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $token)) {
            throw new HttpException(404);
        }
        $table = $type === 'quote' ? 'quotes' : 'invoices';
        $doc = App::db()->one("SELECT * FROM $table WHERE public_token = ? AND deleted_at IS NULL", [$token]);
        if (!$doc || $doc['status'] === 'Draft') {
            throw new HttpException(404);
        }
        return $doc;
    }

    public function document(Request $r, string $type, string $token): Response
    {
        if ($type === 'contract') {
            return $this->contract($r, $token);
        }
        $doc = $this->findDoc($type, $token);
        Session::start();
        $extra = \App\Core\Auth::actingAs(0, (int) $doc['company_id'], fn () => [
            'gateways' => $type === 'invoice' ? \App\Services\Payments\PaymentService::enabled() : [],
            'signature' => \App\Services\SignatureService::forRecord($type === 'quote' ? 'quotes' : 'invoices', (int) $doc['id']),
        ]) ?? ['gateways' => [], 'signature' => null];
        return Response::html(View::partial('public/document', SalesService::documentData($type, $doc) + $extra + ['token' => $token, 'flash' => Session::takeFlash()]));
    }

    public function documentPdf(Request $r, string $type, string $token): Response
    {
        $doc = $this->findDoc($type, $token);
        $pdf = SalesService::pdf($type, $doc);
        return Response::download($pdf, $doc['number'] . (str_starts_with($pdf, '%PDF') ? '.pdf' : '.html'), str_starts_with($pdf, '%PDF') ? 'application/pdf' : 'text/html');
    }

    public function respond(Request $r, string $type, string $token): Response
    {
        $doc = $this->findDoc($type, $token);
        if ($type !== 'quote' || $doc['status'] !== 'Sent') {
            throw new HttpException(422, 'This quotation can no longer be answered.');
        }
        if (!empty($doc['valid_until']) && $doc['valid_until'] < gmdate('Y-m-d')) {
            throw new HttpException(422, 'This quotation has expired. Please contact us for an updated quote.');
        }
        $accept = $r->str('decision') === 'accept';
        $name = mb_substr(trim($r->str('name')), 0, 150);
        if ($accept && $name === '') {
            Session::flash('error', 'Please type your name to accept.');
            return Response::redirect('/p/quote/' . $token);
        }
        $signature = (string) $r->input('signature', '');
        if ($accept) {
            try {
                \App\Services\SignatureService::validateImage($signature);
            } catch (HttpException $e) {
                Session::flash('error', $e->getMessage());
                return Response::redirect('/p/quote/' . $token);
            }
        }
        // Conditional update: only the first response wins (prevents double-submits and replays).
        App::db()->transaction(function () use ($accept, $name, $doc, $signature, $r) {
            $changed = App::db()->query("UPDATE quotes SET status = ?, accepted_at = ?, accepted_by_name = ? WHERE id = ? AND status = 'Sent'", [$accept ? 'Accepted' : 'Declined', $accept ? DB::now() : null, $accept ? $name : null, $doc['id']])->rowCount();
            if ($changed !== 1) {
                throw new HttpException(422, 'This quotation can no longer be answered.');
            }
            if ($accept) {
                \App\Services\SignatureService::sign('quotes', $doc, $name, filter_var($r->str('email'), FILTER_VALIDATE_EMAIL) ?: null, $signature);
            }
        });
        Auth::actingAs((int) ($doc['assigned_to'] ?? $doc['created_by'] ?? 0), (int) $doc['company_id'], function () use ($doc, $accept, $name, $r) {
            ActivityService::log('quotes', (int) $doc['id'], 'system', ($accept ? "Accepted online by $name" : 'Declined online') . ' from IP ' . $r->ip());
            $owner = (int) ($doc['assigned_to'] ?: $doc['created_by']);
            NotificationService::notify($owner, 'quote.' . ($accept ? 'accepted' : 'declined'), 'Quotation ' . $doc['number'] . ($accept ? ' accepted' : ' declined'), $accept ? "Accepted by $name." : 'The customer declined the quotation.', '/r/quotes/' . $doc['id'], (int) $doc['company_id']);
        });
        Session::flash('success', $accept ? 'Thank you! The quotation has been accepted.' : 'Your response has been recorded.');
        return Response::redirect('/p/quote/' . $token);
    }

    public function form(Request $r, string $token): Response
    {
        $form = $this->findForm($token);
        SecurityHeaders::$allowFraming = true;
        Session::start();
        $ts = time();
        return Response::html(View::partial('public/form', ['form' => $form, 'fields' => json_decode((string) $form['fields'], true) ?: [], 'sent' => ($r->query['sent'] ?? '') === '1', 'ts' => $ts, 'sig' => hash_hmac('sha256', $token . '|' . $ts, (string) App::config('app_key'))]));
    }

    private function findForm(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $token)) {
            throw new HttpException(404);
        }
        $form = App::db()->one('SELECT * FROM lead_forms WHERE token = ? AND is_active = 1', [$token]);
        if (!$form) {
            throw new HttpException(404);
        }
        return $form;
    }

    public function submitForm(Request $r, string $token): Response
    {
        $form = $this->findForm($token);
        SecurityHeaders::$allowFraming = true;
        // Spam controls: honeypot, minimum fill time with signed timestamp, per-IP limit.
        $ts = (int) $r->input('_ts', 0);
        $sig = (string) $r->input('_sig', '');
        $validSig = hash_equals(hash_hmac('sha256', $token . '|' . $ts, (string) App::config('app_key')), $sig);
        if ($r->str('website_url') !== '' || !$validSig || time() - $ts < 3 || time() - $ts > 86400) {
            return Response::redirect('/forms/' . $token . '?sent=1');
        }
        if (!RateLimiter::hit('form:' . $form['id'] . ':' . $r->ip(), 5, 3600)) {
            throw new HttpException(429);
        }
        $allowed = json_decode((string) $form['fields'], true) ?: [];
        $input = [];
        foreach ($allowed as $f) {
            $name = (string) ($f['name'] ?? '');
            if (in_array($name, ['name', 'company_name', 'email', 'phone', 'whatsapp', 'job_title', 'website', 'city', 'country', 'industry', 'company_size', 'estimated_value', 'description'], true)) {
                $v = $r->input($name);
                if (is_string($v) && trim($v) !== '') {
                    $input[$name] = mb_substr(trim($v), 0, $name === 'description' ? 5000 : 190);
                }
            }
        }
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $u) {
            if ($r->str($u) !== '') {
                $input[$u] = mb_substr($r->str($u), 0, 100);
            }
        }
        if (empty($input['name']) && empty($input['email'])) {
            return Response::redirect('/forms/' . $token);
        }
        $input['name'] ??= (string) ($input['email'] ?? 'Website visitor');
        $input['status'] = $form['default_status'] ?: 'New';
        $input['source'] = $form['default_source'] ?: 'Website';
        $result = Auth::actingAs((int) ($form['assigned_to'] ?: $form['created_by']), (int) $form['company_id'], function () use ($input, $form) {
            $svc = RecordService::for('leads');
            try {
                $lead = $svc->create($input + ['assigned_to' => $form['assigned_to'] ?: null], ['skip_permission' => true]);
            } catch (HttpException $e) {
                return null;
            }
            ActivityService::log('leads', (int) $lead['id'], 'system', 'Captured from web form "' . $form['name'] . '"');
            return $lead;
        });
        if ($result) {
            App::db()->query('UPDATE lead_forms SET submissions = submissions + 1 WHERE id = ?', [$form['id']]);
        }
        if (!empty($form['redirect_url']) && preg_match('#^https://#i', (string) $form['redirect_url'])) {
            $resp = new Response('', 302);
            $resp->headers['Location'] = $form['redirect_url'];
            return $resp;
        }
        return Response::redirect('/forms/' . $token . '?sent=1');
    }

    /** Incoming webhook → runs a workflow of trigger type "webhook". Body JSON is matched to a record by id or creates a lead. */
    public function workflowHook(Request $r, string $token): Response
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $token)) {
            throw new HttpException(404);
        }
        $wf = App::db()->one("SELECT * FROM workflows WHERE webhook_token = ? AND trigger_type = 'webhook' AND is_active = 1", [$token]);
        if (!$wf) {
            throw new HttpException(404);
        }
        $data = $r->json();
        $res = Auth::actingAs((int) $wf['created_by'], (int) $wf['company_id'], function () use ($wf, $data) {
            $svc = RecordService::for($wf['entity']);
            $id = (int) ($data['id'] ?? 0);
            $record = $id ? App::db()->one('SELECT * FROM ' . App::db()->ident($svc->table) . ' WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [$id, $wf['company_id']]) : null;
            if (!$record) {
                $input = array_intersect_key($data, $svc->def['fields']);
                $created = $svc->create($input, ['skip_permission' => true]);
                $record = App::db()->find($svc->table, (int) $created['id']);
            }
            return WorkflowService::execute($wf, $wf['entity'], $record) + ['record_id' => (int) $record['id']];
        });
        return $this->json(['ok' => true, 'status' => $res['status'] ?? 'failed', 'record_id' => $res['record_id'] ?? null]);
    }

    public function webmanifest(Request $r): Response
    {
        $name = (string) setting('app.name', 'NextaCRM');
        return (new Response((string) json_encode([
            'name' => $name, 'short_name' => $name, 'start_url' => url('/'), 'scope' => url('/'), 'display' => 'standalone',
            'background_color' => '#ffffff', 'theme_color' => '#4f46e5',
            'icons' => [['src' => asset('img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'], ['src' => asset('img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png']],
        ], JSON_UNESCAPED_SLASHES), 200, ['Content-Type' => 'application/manifest+json']));
    }

    // ── Contracts: public review & e-signature ──
    private function findContract(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $token)) {
            throw new HttpException(404);
        }
        $c = App::db()->one("SELECT * FROM contracts WHERE public_token = ? AND deleted_at IS NULL AND status <> 'Draft'", [$token]);
        if (!$c) {
            throw new HttpException(404);
        }
        return $c;
    }

    public function contract(Request $r, string $token): Response
    {
        $c = $this->findContract($token);
        Session::start();
        $db = App::db();
        return Response::html(View::partial('public/contract', [
            'c' => $c, 'token' => $token, 'company' => $db->find('companies', (int) $c['company_id']),
            'customer' => $db->find('customers', (int) $c['customer_id']),
            'signature' => \App\Services\SignatureService::forRecord('contracts', (int) $c['id']),
            'flash' => Session::takeFlash(),
        ]));
    }

    public function signContract(Request $r, string $token): Response
    {
        $c = $this->findContract($token);
        Session::start();
        try {
            App::db()->transaction(function () use ($c, $r) {
                $changed = App::db()->query('UPDATE contracts SET signed_at = ?, signed_by_name = ? WHERE id = ? AND signed_at IS NULL', [DB::now(), mb_substr(trim($r->str('name')), 0, 150), $c['id']])->rowCount();
                if ($changed !== 1) {
                    throw new HttpException(422, 'This contract has already been signed.');
                }
                \App\Services\SignatureService::sign('contracts', $c, $r->str('name'), filter_var($r->str('email'), FILTER_VALIDATE_EMAIL) ?: null, (string) $r->input('signature', ''));
            });
        } catch (HttpException $e) {
            Session::flash('error', $e->getMessage());
            return Response::redirect('/p/contract/' . $token);
        }
        Auth::actingAs((int) ($c['assigned_to'] ?: $c['created_by']), (int) $c['company_id'], function () use ($c, $r) {
            ActivityService::log('contracts', (int) $c['id'], 'system', 'Signed online by ' . mb_substr(trim($r->str('name')), 0, 150) . ' from IP ' . $r->ip());
            NotificationService::notify((int) ($c['assigned_to'] ?: $c['created_by']), 'contract.signed', 'Contract signed: ' . $c['title'], 'Signed by ' . mb_substr(trim($r->str('name')), 0, 150), '/r/contracts/' . $c['id'], (int) $c['company_id']);
        });
        Session::flash('success', 'Thank you. The contract has been signed.');
        return Response::redirect('/p/contract/' . $token);
    }
}

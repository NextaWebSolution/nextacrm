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
use App\Services\CompanyService;
use App\Services\LookupService;
use App\Services\RecordService;
use App\Services\SalesService;

/** Quotation and invoice editor, PDF, e-mail, status, conversions. */
final class DocumentController extends Controller
{
    private function type(string $type): string
    {
        if (!in_array($type, ['quote', 'invoice'], true)) {
            throw new HttpException(404);
        }
        $this->service(SalesService::entityFor($type));
        return $type;
    }

    public function create(Request $r, string $type): Response
    {
        $type = $this->type($type);
        Permissions::authorize(SalesService::entityFor($type), 'create');
        $company = CompanyService::current();
        $doc = [
            'id' => null, 'number' => 'Assigned on save', 'customer_id' => $r->int('customer_id') ?: null, 'contact_id' => null,
            'project_id' => $r->int('project_id') ?: null, 'deal_id' => $r->int('deal_id') ?: null, 'subject' => '', 'currency' => $company['currency'] ?? 'INR',
            'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+' . (int) ($company['payment_terms_days'] ?? 15) . ' days')),
            'valid_until' => date('Y-m-d', strtotime('+15 days')), 'discount_type' => 'percent', 'discount_value' => 0, 'adjustment' => 0,
            'notes' => '', 'terms' => (string) ($company['invoice_terms'] ?? ''), 'status' => 'Draft', 'is_recurring' => 0, 'recurring_interval' => 'Monthly',
            '_labels' => [],
        ];
        if ($doc['customer_id']) {
            $doc['_labels']['customer_id'] = \App\Services\LookupService::titles('customers', [$doc['customer_id']])[$doc['customer_id']] ?? null;
            $cur = App::db()->value('SELECT currency FROM customers WHERE id = ? AND company_id = ?', [$doc['customer_id'], Auth::companyId()]);
            if ($cur) {
                $doc['currency'] = $cur;
            }
        }
        if ($doc['deal_id']) {
            $deal = RecordService::for('deals')->find($doc['deal_id']);
            $doc['subject'] = $deal['name'];
            $doc['_labels']['deal_id'] = $deal['name'];
        }
        if ($doc['project_id']) {
            $doc['_labels']['project_id'] = \App\Services\LookupService::titles('projects', [$doc['project_id']])[$doc['project_id']] ?? null;
        }
        return $this->editor($type, $doc, []);
    }

    public function edit(Request $r, string $type, string $id): Response
    {
        $type = $this->type($type);
        $doc = RecordService::for(SalesService::entityFor($type))->find((int) $id, 'edit');
        return $this->editor($type, $doc, SalesService::items($type, (int) $id));
    }

    private function editor(string $type, array $doc, array $items): Response
    {
        $products = App::db()->all('SELECT id, name, unit, price, tax_id, description FROM products WHERE company_id = ? AND deleted_at IS NULL AND is_active = 1 ORDER BY name LIMIT 500', [Auth::companyId()]);
        return $this->view('documents/editor', [
            'title' => ($doc['id'] ? 'Edit ' : 'New ') . ($type === 'quote' ? 'quotation' : 'invoice'),
            'type' => $type, 'doc' => $doc, 'items' => $items, 'products' => $products,
            'taxes' => LookupService::taxes(), 'currencies' => LookupService::currencies(),
        ]);
    }

    public function store(Request $r, string $type): Response
    {
        $type = $this->type($type);
        $doc = SalesService::save($type, null, $r->all());
        return $this->json(['ok' => true, 'message' => ucfirst($type) . ' ' . $doc['number'] . ' created', 'redirect' => url('/r/' . SalesService::entityFor($type) . '/' . $doc['id'])]);
    }

    public function update(Request $r, string $type, string $id): Response
    {
        $type = $this->type($type);
        $doc = SalesService::save($type, (int) $id, $r->all());
        return $this->json(['ok' => true, 'message' => 'Saved', 'redirect' => url('/r/' . SalesService::entityFor($type) . '/' . $doc['id'])]);
    }

    public function pdf(Request $r, string $type, string $id): Response
    {
        $type = $this->type($type);
        $doc = RecordService::for(SalesService::entityFor($type))->find((int) $id);
        $pdf = SalesService::pdf($type, $doc);
        $isPdf = str_starts_with($pdf, '%PDF');
        return Response::download($pdf, $doc['number'] . ($isPdf ? '.pdf' : '.html'), $isPdf ? 'application/pdf' : 'text/html; charset=UTF-8', ($r->query['inline'] ?? '') === '1');
    }

    public function status(Request $r, string $type, string $id): Response
    {
        $type = $this->type($type);
        SalesService::setStatus($type, (int) $id, $r->str('status'));
        return $this->json(['ok' => true, 'message' => 'Status updated', 'reload' => true]);
    }

    public function email(Request $r, string $type, string $id): Response
    {
        $type = $this->type($type);
        SalesService::email($type, (int) $id, $r->str('to'), $r->str('subject'), $r->str('message'));
        return $this->json(['ok' => true, 'message' => 'E-mail queued for delivery', 'reload' => true]);
    }

    public function convert(Request $r, string $id): Response
    {
        $this->type('quote');
        $invoiceId = SalesService::quoteToInvoice((int) $id);
        return $this->json(['ok' => true, 'message' => 'Invoice created', 'redirect' => url('/r/invoices/' . $invoiceId)]);
    }

    public function fromTime(Request $r, string $id): Response
    {
        $this->type('invoice');
        $invoiceId = SalesService::invoiceFromTime((int) $id, $r->str('from') ?: null, $r->str('to') ?: null);
        return $this->json(['ok' => true, 'message' => 'Invoice created from billable time', 'redirect' => url('/r/invoices/' . $invoiceId)]);
    }
}

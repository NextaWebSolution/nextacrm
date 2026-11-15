<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Events;
use App\Core\HttpException;
use App\Core\Mailer;
use App\Core\Permissions;

/**
 * Quotations and invoices: line items, totals, numbering, conversions, PDF, e-mail, recurring billing.
 */
final class SalesService
{
    public static function entityFor(string $type): string
    {
        return $type === 'quote' ? 'quotes' : 'invoices';
    }

    public static function items(string $type, int $id): array
    {
        return App::db()->all('SELECT * FROM line_items WHERE parent_type = ? AND parent_id = ? ORDER BY sort, id', [$type, $id]);
    }

    /**
     * Create or update a quote/invoice together with its line items.
     * @param array $input header fields + items[] + discount_type + discount_value + adjustment
     */
    public static function save(string $type, ?int $id, array $input): array
    {
        $entity = self::entityFor($type);
        $svc = RecordService::for($entity);
        $db = App::db();
        $items = self::normalizeItems((array) ($input['items'] ?? []));
        if (!$items) {
            throw new HttpException(422, 'Add at least one line item.', ['items' => 'Add at least one line item.']);
        }
        $header = array_diff_key($input, array_flip(['items', 'discount_type', 'discount_value', 'adjustment', '_token', 'number', 'total', 'status']));
        if ($type === 'quote' && isset($input['status']) && in_array($input['status'], ['Draft', 'Sent', 'Accepted', 'Declined', 'Expired'], true)) {
            $header['status'] = $input['status'];
        }

        return $db->transaction(function () use ($type, $id, $svc, $db, $items, $header, $input, $entity) {
            $old = $id ? $db->one('SELECT * FROM ' . $db->ident($svc->table) . ' WHERE id = ? AND company_id = ? AND deleted_at IS NULL' . $db->forUpdate(), [$id, Auth::companyId()]) : null;
            if ($id && !$old) {
                throw new HttpException(404);
            }
            if ($type === 'invoice' && $old && in_array($old['status'], ['Paid', 'Cancelled'], true)) {
                throw new HttpException(422, 'Paid or cancelled invoices cannot be edited. Issue a new invoice or credit instead.');
            }
            $record = $id ? $svc->update($id, $header, ['silent' => true]) : $svc->create($header, ['silent' => true]);
            $rid = (int) $record['id'];
            $db->delete('line_items', ['parent_type' => $type, 'parent_id' => $rid]);
            foreach ($items as $i => $it) {
                $db->insert('line_items', $it + ['parent_type' => $type, 'parent_id' => $rid, 'sort' => $i]);
            }
            $discType = ($input['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
            $discValue = max(0, (float) ($input['discount_value'] ?? 0));
            $adjust = round((float) ($input['adjustment'] ?? 0), 2);
            $totals = self::computeTotals($items, $discType, $discValue, $adjust);
            if ($totals['total'] < 0) {
                throw new HttpException(422, 'The document total cannot be negative.', ['items' => 'The document total cannot be negative.']);
            }
            if ($type === 'invoice' && $old) {
                $paid = (float) $db->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ? AND deleted_at IS NULL', [$id]);
                if ($totals['total'] + 0.004 < $paid) {
                    throw new HttpException(422, 'The new total is lower than the amount already paid (' . number_format($paid, 2) . ').');
                }
            }
            $extra = [];
            if ($type === 'quote' && $old && ($old['approval_status'] ?? null) === 'approved' && abs((float) $totals['total'] - (float) $old['total']) > 0.004) {
                $extra['approval_status'] = null; // the amount changed after approval → approve again
            }
            $db->update($svc->table, $totals + $extra + ['discount_type' => $discType, 'discount_value' => $discValue, 'adjustment' => $adjust], ['id' => $rid]);
            if ($type === 'invoice') {
                self::recalcInvoice($rid);
            }
            $fresh = $db->find($svc->table, $rid);
            Events::dispatch($id ? 'record.updated' : 'record.created', ['entity' => $entity, 'record' => $fresh, 'old' => $old, 'changes' => $old ? Audit::diff($old, $fresh) : []]);
            return $svc->find($rid);
        });
    }

    private static function normalizeItems(array $raw): array
    {
        $out = [];
        $taxes = [];
        foreach (LookupService::taxes() as $t) {
            $taxes[(int) $t['id']] = (float) $t['rate'];
        }
        foreach (array_slice($raw, 0, 200) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $desc = trim((string) ($r['description'] ?? ''));
            $qty = (float) str_replace(',', '', (string) ($r['quantity'] ?? 1));
            $price = (float) str_replace(',', '', (string) ($r['unit_price'] ?? 0));
            // Reject non-finite / absurd values; quantities cannot be negative (use a negative price line for credits).
            if (!is_finite($qty) || !is_finite($price) || $qty < 0 || $qty > 1e7 || abs($price) > 1e10) {
                throw new HttpException(422, 'Line ' . (count($out) + 1) . ' has an invalid quantity or price.', ['items' => 'Invalid quantity or price.']);
            }
            if ($desc === '' && $price == 0.0) {
                continue;
            }
            $disc = max(0, min(100, (float) ($r['discount_percent'] ?? 0)));
            $taxId = (int) ($r['tax_id'] ?? 0);
            $rate = $taxes[$taxId] ?? 0.0;
            $net = round($qty * $price * (1 - $disc / 100), 2);
            $productId = (int) ($r['product_id'] ?? 0);
            if ($productId && !App::db()->value('SELECT id FROM products WHERE id = ? AND company_id = ?', [$productId, Auth::companyId()])) {
                $productId = 0;
            }
            $timeIds = array_values(array_filter(array_map('intval', explode(',', (string) ($r['time_entry_ids'] ?? '')))));
            $out[] = [
                'product_id' => $productId ?: null,
                'description' => mb_substr($desc, 0, 5000),
                'quantity' => round($qty, 3),
                'unit' => mb_substr((string) ($r['unit'] ?? ''), 0, 20) ?: null,
                'unit_price' => round($price, 2),
                'discount_percent' => $disc,
                'tax_id' => isset($taxes[$taxId]) ? $taxId : null,
                'tax_rate' => $rate,
                'tax_amount' => round($net * $rate / 100, 2),
                'line_total' => $net,
                'time_entry_ids' => $timeIds ? implode(',', $timeIds) : null,
            ];
        }
        return $out;
    }

    public static function computeTotals(array $items, string $discType, float $discValue, float $adjust = 0): array
    {
        $subtotal = 0.0;
        $tax = 0.0;
        foreach ($items as $it) {
            $subtotal += (float) $it['line_total'];
            $tax += (float) $it['tax_amount'];
        }
        $discount = $discType === 'fixed' ? min($discValue, $subtotal) : round($subtotal * min($discValue, 100) / 100, 2);
        // Document-level discount reduces the taxable base proportionally.
        $ratio = $subtotal > 0 ? ($subtotal - $discount) / $subtotal : 1;
        $tax = round($tax * $ratio, 2);
        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discount, 2),
            'tax_total' => $tax,
            'total' => round($subtotal - $discount + $tax + $adjust, 2),
        ];
    }

    /** Recompute paid amount, balance and status from payments. */
    public static function recalcInvoice(int $invoiceId): void
    {
        if (!$invoiceId) {
            return;
        }
        $db = App::db();
        $inv = $db->find('invoices', $invoiceId);
        if (!$inv) {
            return;
        }
        $paid = (float) $db->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ? AND deleted_at IS NULL', [$invoiceId]);
        $total = (float) $inv['total'];
        $balance = round($total - $paid, 2);
        $status = $inv['status'];
        $paidAt = $inv['paid_at'];
        if ($status !== 'Cancelled') {
            if ($total > 0 && $balance <= 0.004) {
                $status = 'Paid';
                $paidAt = $paidAt ?: DB::now();
            } elseif ($paid > 0) {
                $status = 'Partially Paid';
                $paidAt = null;
            } elseif (in_array($status, ['Paid', 'Partially Paid'], true)) {
                $status = $inv['sent_at'] ? 'Sent' : 'Draft';
                $paidAt = null;
            }
            if (in_array($status, ['Sent', 'Partially Paid'], true) && $inv['due_date'] && $inv['due_date'] < date('Y-m-d')) {
                $status = 'Overdue';
            }
        }
        $db->update('invoices', ['amount_paid' => round($paid, 2), 'balance' => max(0, $balance), 'status' => $status, 'paid_at' => $paidAt], ['id' => $invoiceId]);
    }

    public static function setStatus(string $type, int $id, string $status): array
    {
        $entity = self::entityFor($type);
        $svc = RecordService::for($entity);
        $rec = $svc->find($id, 'edit');
        $allowed = $type === 'quote' ? ['Draft', 'Sent', 'Accepted', 'Declined', 'Expired'] : ['Draft', 'Sent', 'Cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new HttpException(422, 'Invalid status.');
        }
        $data = ['status' => $status];
        if ($type === 'quote') {
            ApprovalService::guard('quotes', $data, App::db()->find('quotes', $id));
        }
        if ($status === 'Sent' && empty($rec['sent_at'])) {
            $data['sent_at'] = DB::now();
        }
        App::db()->update($svc->table, $data, ['id' => $id]);
        if ($type === 'invoice' && $status !== 'Cancelled') {
            self::recalcInvoice($id);
        }
        $fresh = App::db()->find($svc->table, $id);
        ActivityService::log($entity, $id, 'system', 'Status: ' . $rec['status'] . ' → ' . $fresh['status']);
        Events::dispatch('record.updated', ['entity' => $entity, 'record' => $fresh, 'old' => $rec, 'changes' => ['status' => ['from' => $rec['status'], 'to' => $fresh['status']]]]);
        return $fresh;
    }

    public static function quoteToInvoice(int $quoteId): int
    {
        Permissions::authorize('invoices', 'create');
        $q = RecordService::for('quotes')->find($quoteId);
        if (!empty($q['invoice_id']) && App::db()->value('SELECT id FROM invoices WHERE id = ? AND deleted_at IS NULL', [$q['invoice_id']])) {
            return (int) $q['invoice_id'];
        }
        $items = self::items('quote', $quoteId);
        $inv = self::save('invoice', null, [
            'customer_id' => $q['customer_id'], 'contact_id' => $q['contact_id'], 'project_id' => $q['project_id'],
            'subject' => $q['subject'], 'currency' => $q['currency'], 'notes' => $q['notes'], 'terms' => $q['terms'],
            'discount_type' => $q['discount_type'], 'discount_value' => $q['discount_value'], 'adjustment' => $q['adjustment'],
            'items' => $items,
        ]);
        App::db()->update('invoices', ['quote_id' => $quoteId], ['id' => $inv['id']]);
        App::db()->update('quotes', ['status' => 'Invoiced', 'invoice_id' => $inv['id']], ['id' => $quoteId]);
        ActivityService::log('quotes', $quoteId, 'system', 'Converted to invoice ' . $inv['number']);
        return (int) $inv['id'];
    }

    /** Creates an invoice from unbilled billable time entries of a project. */
    public static function invoiceFromTime(int $projectId, ?string $from = null, ?string $to = null): int
    {
        Permissions::authorize('invoices', 'create');
        $project = RecordService::for('projects')->find($projectId);
        $db = App::db();
        $sql = 'SELECT te.*, t.title AS task_title FROM time_entries te LEFT JOIN tasks t ON t.id = te.task_id WHERE te.project_id = ? AND te.company_id = ? AND te.deleted_at IS NULL AND te.is_billable = 1 AND te.is_invoiced = 0 AND te.is_running = 0';
        $params = [$projectId, Auth::companyId()];
        if ($from) { $sql .= ' AND te.entry_date >= ?'; $params[] = $from; }
        if ($to) { $sql .= ' AND te.entry_date <= ?'; $params[] = $to; }
        $entries = $db->all($sql . ' ORDER BY te.entry_date', $params);
        if (!$entries) {
            throw new HttpException(422, 'There is no unbilled billable time for this project.');
        }
        $groups = [];
        foreach ($entries as $e) {
            $key = ($e['task_id'] ?: 0) . '|' . (float) ($e['hourly_rate'] ?: $project['hourly_rate']);
            $groups[$key] ??= ['title' => $e['task_title'] ?: 'General work', 'minutes' => 0, 'rate' => (float) ($e['hourly_rate'] ?: $project['hourly_rate'] ?: 0), 'ids' => []];
            $groups[$key]['minutes'] += (int) $e['minutes'];
            $groups[$key]['ids'][] = (int) $e['id'];
        }
        $defaultTax = $db->value('SELECT id FROM taxes WHERE company_id = ? AND is_default = 1', [Auth::companyId()]);
        $items = [];
        foreach ($groups as $g) {
            $items[] = ['description' => $project['name'] . ' — ' . $g['title'], 'quantity' => round($g['minutes'] / 60, 2), 'unit' => 'hour', 'unit_price' => $g['rate'], 'tax_id' => $defaultTax, 'time_entry_ids' => implode(',', $g['ids'])];
        }
        $inv = self::save('invoice', null, ['customer_id' => $project['customer_id'], 'project_id' => $projectId, 'currency' => $project['currency'] ?: CompanyService::currentCurrency(), 'subject' => 'Services for ' . $project['name'], 'items' => $items]);
        $ids = implode(',', array_map('intval', array_column($entries, 'id')));
        $db->query("UPDATE time_entries SET is_invoiced = 1, invoice_id = ? WHERE id IN ($ids)", [$inv['id']]);
        return (int) $inv['id'];
    }

    public static function nextRecurringDate(string $from, string $interval): string
    {
        $mod = match ($interval) {
            'Weekly' => '+1 week', 'Quarterly' => '+3 months', 'Half-yearly' => '+6 months', 'Yearly' => '+1 year', default => '+1 month',
        };
        return date('Y-m-d', strtotime($from . ' ' . $mod));
    }

    /** Cron: create the next invoice for every due recurring template. */
    public static function generateRecurring(): int
    {
        $db = App::db();
        $today = date('Y-m-d');
        $due = $db->all("SELECT * FROM invoices WHERE is_recurring = 1 AND deleted_at IS NULL AND status <> 'Cancelled' AND recurring_next_date IS NOT NULL AND recurring_next_date <= ? AND (recurring_end_date IS NULL OR recurring_next_date <= recurring_end_date) LIMIT 50", [$today]);
        $n = 0;
        foreach ($due as $tpl) {
            $db->transaction(function () use ($db, $tpl, &$n) {
                $company = $db->find('companies', (int) $tpl['company_id']);
                $num = self::reserveNumberFor((int) $tpl['company_id']);
                $issue = $tpl['recurring_next_date'];
                $data = $tpl;
                unset($data['id']);
                $data = array_merge($data, [
                    'number' => $num, 'status' => 'Draft', 'issue_date' => $issue,
                    'due_date' => date('Y-m-d', strtotime($issue . ' +' . (int) ($company['payment_terms_days'] ?? 15) . ' days')),
                    'amount_paid' => 0, 'balance' => $tpl['total'], 'paid_at' => null, 'sent_at' => null,
                    'is_recurring' => 0, 'recurring_interval' => null, 'recurring_next_date' => null, 'recurring_end_date' => null,
                    'recurring_parent_id' => $tpl['id'], 'public_token' => Crypto::token(24), 'quote_id' => null,
                    'created_at' => DB::now(), 'updated_at' => DB::now(),
                ]);
                $newId = $db->insert('invoices', $data);
                foreach (self::items('invoice', (int) $tpl['id']) as $it) {
                    unset($it['id']);
                    $it['parent_id'] = $newId;
                    $it['time_entry_ids'] = null;
                    $db->insert('line_items', $it);
                }
                $db->update('invoices', ['recurring_next_date' => self::nextRecurringDate($tpl['recurring_next_date'], (string) $tpl['recurring_interval'])], ['id' => $tpl['id']]);
                if ($tpl['assigned_to']) {
                    NotificationService::notify((int) $tpl['assigned_to'], 'invoice.recurring', 'Recurring invoice ' . $num . ' created', 'A draft invoice was generated from ' . $tpl['number'] . '. Review and send it.', '/r/invoices/' . $newId, (int) $tpl['company_id']);
                }
                $n++;
            });
        }
        return $n;
    }

    private static function reserveNumberFor(int $companyId): string
    {
        $db = App::db();
        $row = $db->one('SELECT invoice_prefix AS p, invoice_next AS n FROM companies WHERE id = ?', [$companyId]);
        $n = (int) $row['n'];
        do {
            $number = $row['p'] . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $exists = $db->value('SELECT id FROM invoices WHERE company_id = ? AND number = ?', [$companyId, $number]);
            $n++;
        } while ($exists);
        $db->update('companies', ['invoice_next' => $n], ['id' => $companyId]);
        return $number;
    }

    /** Cron: overdue invoices and expired quotes. */
    public static function markOverdue(): int
    {
        $db = App::db();
        $today = date('Y-m-d');
        $n = $db->query("UPDATE invoices SET status = 'Overdue' WHERE deleted_at IS NULL AND status IN ('Sent','Partially Paid') AND due_date < ? AND balance > 0", [$today])->rowCount();
        $n += $db->query("UPDATE quotes SET status = 'Expired' WHERE deleted_at IS NULL AND status = 'Sent' AND valid_until IS NOT NULL AND valid_until < ?", [$today])->rowCount();
        return $n;
    }

    // ───────────── Rendering ─────────────

    public static function documentData(string $type, array $doc): array
    {
        $db = App::db();
        $company = $db->find('companies', (int) $doc['company_id']) ?? [];
        $customer = $doc['customer_id'] ? $db->find('customers', (int) $doc['customer_id']) : null;
        $contact = $doc['contact_id'] ? $db->find('contacts', (int) $doc['contact_id']) : null;
        $logo = null;
        if (!empty($company['logo_file_id'])) {
            $logo = FileService::dataUri((int) $company['logo_file_id']);
        }
        $payments = $type === 'invoice' ? $db->all('SELECT * FROM payments WHERE invoice_id = ? AND deleted_at IS NULL ORDER BY paid_on', [$doc['id']]) : [];
        $taxSummary = [];
        foreach (self::items($type, (int) $doc['id']) as $it) {
            if ((float) $it['tax_rate'] > 0) {
                $k = (string) (float) $it['tax_rate'];
                $taxSummary[$k] = ($taxSummary[$k] ?? 0) + (float) $it['tax_amount'];
            }
        }
        return ['type' => $type, 'doc' => $doc, 'items' => self::items($type, (int) $doc['id']), 'company' => $company, 'customer' => $customer, 'contact' => $contact, 'logo' => $logo, 'payments' => $payments, 'taxSummary' => $taxSummary,
            'signature' => $type === 'quote' ? SignatureService::forRecord('quotes', (int) $doc['id']) : null];
    }

    public static function pdf(string $type, array $doc): string
    {
        return self::renderPdf(\App\Core\View::partial('documents/pdf', self::documentData($type, $doc)));
    }

    /** Renders trusted, escaped HTML to PDF with remote resources, PHP and JS disabled. */
    public static function renderPdf(string $html): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return $html;
        }
        $tmp = NX_ROOT . '/storage/tmp';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0700, true);
        }
        $opts = new \Dompdf\Options();
        $opts->set('isRemoteEnabled', false);
        $opts->set('isPhpEnabled', false);
        $opts->set('isJavascriptEnabled', false);
        $opts->set('defaultFont', 'DejaVu Sans');
        $opts->set('tempDir', $tmp);
        $opts->set('fontCache', $tmp);
        $opts->set('chroot', [NX_ROOT . '/vendor/dompdf']);
        $pdf = new \Dompdf\Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return (string) $pdf->output();
    }

    public static function publicUrl(string $type, array $doc): string
    {
        return full_url('/p/' . $type . '/' . $doc['public_token']);
    }

    public static function email(string $type, int $id, string $to, string $subject, string $message): void
    {
        $entity = self::entityFor($type);
        $doc = RecordService::for($entity)->find($id, 'edit');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Enter a valid e-mail address.', ['to' => 'Invalid e-mail address.']);
        }
        $pdf = self::pdf($type, $doc);
        $isPdf = str_starts_with($pdf, '%PDF');
        $link = self::publicUrl($type, $doc);
        $body = nl2br_safe($message) . '<p style="margin-top:20px"><a href="' . e($link) . '" style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">View ' . ($type === 'quote' ? 'quotation' : 'invoice') . ' online</a></p>';
        Mailer::queue($to, $subject, $body, $isPdf ? [['name' => $doc['number'] . '.pdf', 'mime' => 'application/pdf', 'content_b64' => base64_encode($pdf)]] : [], Auth::user()['email'] ?? null);
        if (in_array($doc['status'], ['Draft'], true)) {
            self::setStatus($type, $id, 'Sent');
        }
        ActivityService::log($entity, $id, 'email', "Sent to $to", $subject);
    }
}

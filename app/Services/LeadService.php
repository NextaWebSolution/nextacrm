<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Permissions;

final class LeadService
{
    /**
     * Convert a lead into a customer (existing or new), a contact and optionally a deal.
     * @return array{customer_id:int, contact_id:int, deal_id:?int}
     */
    public static function convert(int $leadId, array $opts): array
    {
        $leads = RecordService::for('leads');
        $lead = $leads->find($leadId, 'edit');
        if (!empty($lead['converted_at'])) {
            throw new HttpException(422, 'This lead has already been converted.');
        }
        return App::db()->transaction(function () use ($lead, $opts, $leads) {
            $customerId = (int) ($opts['customer_id'] ?? 0);
            if ($customerId) {
                RecordService::for('customers')->find($customerId);
            } else {
                Permissions::authorize('customers', 'create');
                $c = RecordService::for('customers')->create(array_filter([
                    'name' => $lead['company_name'] ?: $lead['name'],
                    'type' => 'Customer',
                    'email' => $lead['email'],
                    'phone' => $lead['phone'],
                    'website' => $lead['website'],
                    'industry' => $lead['industry'],
                    'company_size' => $lead['company_size'],
                    'annual_revenue' => $lead['annual_revenue'],
                    'billing_address' => $lead['address'],
                    'city' => $lead['city'],
                    'state' => $lead['state'],
                    'country' => $lead['country'],
                    'source' => $lead['source'],
                    'tags' => $lead['tags'],
                    'assigned_to' => $lead['assigned_to'],
                ], fn ($v) => $v !== null && $v !== ''));
                $customerId = (int) $c['id'];
            }
            $parts = preg_split('/\s+/', trim((string) $lead['name']), 2) ?: [''];
            Permissions::authorize('contacts', 'create');
            $contact = RecordService::for('contacts')->create(array_filter([
                'customer_id' => $customerId,
                'first_name' => $parts[0] ?: 'Contact',
                'last_name' => $parts[1] ?? null,
                'email' => $lead['email'],
                'phone' => $lead['phone'],
                'mobile' => $lead['whatsapp'],
                'job_title' => $lead['job_title'],
                'is_primary' => 1,
                'assigned_to' => $lead['assigned_to'],
            ], fn ($v) => $v !== null && $v !== ''));

            $dealId = null;
            if (!empty($opts['create_deal'])) {
                Permissions::authorize('deals', 'create');
                $deal = RecordService::for('deals')->create(array_filter([
                    'name' => trim((string) ($opts['deal_name'] ?? '')) ?: (($lead['company_name'] ?: $lead['name']) . ' — New deal'),
                    'customer_id' => $customerId,
                    'contact_id' => $contact['id'],
                    'amount' => $opts['deal_amount'] ?? $lead['estimated_value'] ?? 0,
                    'currency' => $lead['currency'],
                    'pipeline_id' => $opts['pipeline_id'] ?? null,
                    'source' => $lead['source'],
                    'assigned_to' => $lead['assigned_to'],
                    'expected_close' => $opts['expected_close'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                $dealId = (int) $deal['id'];
                App::db()->update('deals', ['lead_id' => $lead['id']], ['id' => $dealId]);
            }
            App::db()->update('leads', ['status' => 'Converted', 'converted_at' => DB::now(), 'customer_id' => $customerId, 'contact_id' => $contact['id'], 'deal_id' => $dealId], ['id' => $lead['id']]);
            // Carry the history over to the customer.
            App::db()->query("UPDATE activities SET entity = 'customers', record_id = ? WHERE entity = 'leads' AND record_id = ? AND type <> 'system'", [$customerId, $lead['id']]);
            App::db()->query("UPDATE files SET entity = 'customers', record_id = ? WHERE entity = 'leads' AND record_id = ?", [$customerId, $lead['id']]);
            ActivityService::log('leads', (int) $lead['id'], 'system', 'Lead converted to customer #' . $customerId . ($dealId ? ' and deal #' . $dealId : ''));
            ActivityService::log('customers', $customerId, 'system', 'Created from lead: ' . $lead['name']);
            \App\Core\Events::dispatch('lead.converted', ['entity' => 'leads', 'record' => App::db()->find('leads', (int) $lead['id']), 'changes' => []]);
            return ['customer_id' => $customerId, 'contact_id' => (int) $contact['id'], 'deal_id' => $dealId];
        });
    }
}

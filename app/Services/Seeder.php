<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Permissions;
use App\Core\Settings;

final class Seeder
{
    public static function run(DB $db, array $data): void
    {
        $now = DB::now();
        $db->transaction(function () use ($db, $data, $now) {
            // Roles
            $adminRole = (int) $db->value("SELECT id FROM roles WHERE is_admin = 1 LIMIT 1");
            if (!$adminRole) {
                $adminRole = $db->insert('roles', ['name' => 'Administrator', 'description' => 'Full access to everything including settings.', 'is_admin' => 1, 'is_system' => 1, 'permissions' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                foreach (Permissions::defaultRoles() as $name => $r) {
                    $db->insert('roles', ['name' => $name, 'description' => $r['description'], 'is_admin' => 0, 'is_system' => 0, 'permissions' => json_encode($r['permissions']), 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            // Currencies (rates relative to INR = 1; editable in Settings)
            $currencies = ['INR' => ['Indian Rupee', 1], 'USD' => ['US Dollar', 0.0118], 'EUR' => ['Euro', 0.0109], 'GBP' => ['British Pound', 0.0093], 'AED' => ['UAE Dirham', 0.0434], 'AUD' => ['Australian Dollar', 0.0181], 'CAD' => ['Canadian Dollar', 0.0163], 'SGD' => ['Singapore Dollar', 0.0153], 'JPY' => ['Japanese Yen', 1.78], 'SAR' => ['Saudi Riyal', 0.0443], 'ZAR' => ['South African Rand', 0.214], 'NZD' => ['New Zealand Dollar', 0.0199], 'CHF' => ['Swiss Franc', 0.0102], 'BDT' => ['Bangladeshi Taka', 1.42], 'NPR' => ['Nepalese Rupee', 1.6], 'LKR' => ['Sri Lankan Rupee', 3.52]];
            foreach ($currencies as $code => [$name, $rate]) {
                if (!$db->value('SELECT id FROM currencies WHERE code = ?', [$code])) {
                    $db->insert('currencies', ['code' => $code, 'name' => $name, 'rate' => $rate, 'is_active' => 1, 'updated_at' => $now]);
                }
            }

            // Company
            $c = $data['company'] ?? [];
            $companyId = $db->insert('companies', [
                'name' => $c['name'] ?? 'My Company',
                'legal_name' => $c['legal_name'] ?? ($c['name'] ?? null),
                'email' => $c['email'] ?? ($data['admin']['email'] ?? null),
                'phone' => $c['phone'] ?? null,
                'country' => $c['country'] ?? 'India',
                'currency' => strtoupper($c['currency'] ?? 'INR'),
                'timezone' => $c['timezone'] ?? 'Asia/Kolkata',
                'tax_id' => $c['tax_id'] ?? null,
                'address' => $c['address'] ?? null,
                'invoice_terms' => "Payment due within 15 days.\nPlease include the invoice number with your payment.",
                'invoice_footer' => 'Thank you for your business.',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // Admin user
            $a = $data['admin'];
            $adminId = $db->insert('users', [
                'name' => $a['name'], 'email' => mb_strtolower(trim($a['email'])), 'password' => Crypto::hashPassword($a['password']),
                'role_id' => $adminRole, 'status' => 'active', 'timezone' => $c['timezone'] ?? 'Asia/Kolkata', 'default_company_id' => $companyId,
                'email_verified_at' => $now, 'preferences' => '{"theme":"system","email_notifications":true}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('company_user', ['company_id' => $companyId, 'user_id' => $adminId]);

            self::seedCompanyDefaults($db, $companyId, strtoupper($c['currency'] ?? 'INR'), $c['country'] ?? 'India');
            $db->insert('settings', ['company_id' => 0, 'k' => 'app.name', 'v' => json_encode($c['app_name'] ?? 'NextaCRM'), 'updated_at' => $now]);
            $db->insert('settings', ['company_id' => 0, 'k' => 'cron.key', 'v' => json_encode(Crypto::encrypt(Crypto::token(24))), 'updated_at' => $now]);

            if (!empty($data['demo'])) {
                Auth::actingAs($adminId, $companyId, fn () => self::demo($db, $adminId, $companyId));
            }
        });
    }

    public static function seedCompanyDefaults(DB $db, int $companyId, string $currency, string $country): void
    {
        $now = DB::now();
        $isIndia = $currency === 'INR' || stripos($country, 'india') !== false;
        $taxes = $isIndia
            ? [['GST 0%', 0, 0], ['GST 5%', 5, 0], ['GST 12%', 12, 0], ['GST 18%', 18, 1], ['GST 28%', 28, 0]]
            : [['No tax', 0, 0], ['VAT 5%', 5, 0], ['VAT 20%', 20, 1]];
        foreach ($taxes as [$n, $r, $d]) {
            $db->insert('taxes', ['company_id' => $companyId, 'name' => $n, 'rate' => $r, 'is_default' => $d, 'created_at' => $now]);
        }
        $pipelines = [
            'Sales pipeline' => [['Discovery', 10, 'slate'], ['Requirement analysis', 25, 'blue'], ['Proposal sent', 50, 'violet'], ['Negotiation', 75, 'amber'], ['Won', 100, 'green', 1, 0], ['Lost', 0, 'red', 0, 1]],
            'Staff augmentation' => [['Requirement received', 10, 'slate'], ['Profiles shared', 30, 'blue'], ['Interviews', 55, 'violet'], ['Offer', 80, 'amber'], ['Onboarded', 100, 'green', 1, 0], ['Closed lost', 0, 'red', 0, 1]],
        ];
        $i = 0;
        foreach ($pipelines as $name => $stages) {
            $pid = $db->insert('pipelines', ['company_id' => $companyId, 'name' => $name, 'is_default' => $i === 0 ? 1 : 0, 'sort' => $i, 'created_at' => $now]);
            foreach ($stages as $s => $st) {
                $db->insert('pipeline_stages', ['pipeline_id' => $pid, 'name' => $st[0], 'probability' => $st[1], 'color' => $st[2], 'sort' => $s, 'is_won' => $st[3] ?? 0, 'is_lost' => $st[4] ?? 0]);
            }
            $i++;
        }
        foreach (['Sales', 'Delivery', 'Support'] as $t) {
            $db->insert('teams', ['company_id' => $companyId, 'name' => $t, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** A small, realistic data set so a fresh install is explorable. */
    private static function demo(DB $db, int $adminId, int $companyId): void
    {
        $rec = fn (string $e, array $d) => RecordService::for($e)->create($d, ['silent' => true, 'skip_permission' => true]);
        $tax = (int) $db->value('SELECT id FROM taxes WHERE company_id = ? AND is_default = 1', [$companyId]);

        $products = [];
        foreach ([['Website design & development', 'Service', 'project', 85000], ['Custom web application (per sprint)', 'Service', 'unit', 240000], ['Mobile app development', 'Service', 'project', 350000], ['SEO retainer', 'Subscription', 'month', 25000], ['Managed cloud hosting', 'Subscription', 'month', 4500], ['Developer — dedicated (hourly)', 'Service', 'hour', 1800], ['Annual maintenance contract', 'Service', 'year', 60000]] as [$n, $t, $u, $p]) {
            $products[] = $rec('products', ['name' => $n, 'type' => $t, 'unit' => $u, 'price' => $p, 'tax_id' => $tax, 'hsn_sac' => '998314']);
        }

        $customers = [];
        foreach ([['Acme Retail Pvt Ltd', 'Retail', 'Bengaluru', 'priya@acmeretail.example'], ['BlueOcean Logistics', 'Manufacturing', 'Mumbai', 'ops@blueocean.example'], ['Greenleaf Healthcare', 'Healthcare', 'Pune', 'it@greenleaf.example'], ['Skyline Realty', 'Real Estate', 'Gurugram', 'hello@skyline.example'], ['Orbit EdTech', 'Education', 'Hyderabad', 'cto@orbitedu.example']] as [$n, $ind, $city, $mail]) {
            $customers[] = $rec('customers', ['name' => $n, 'type' => 'Customer', 'industry' => $ind, 'city' => $city, 'country' => 'India', 'email' => $mail, 'phone' => '+91 98' . random_int(10000000, 99999999), 'source' => 'Referral']);
        }
        $contactNames = [['Priya', 'Sharma', 'Head of Digital'], ['Rahul', 'Mehta', 'Operations Director'], ['Ananya', 'Iyer', 'IT Manager'], ['Vikram', 'Singh', 'Founder'], ['Neha', 'Kapoor', 'CTO']];
        $contacts = [];
        foreach ($customers as $i => $cu) {
            [$f, $l, $j] = $contactNames[$i];
            $contacts[] = $rec('contacts', ['customer_id' => $cu['id'], 'first_name' => $f, 'last_name' => $l, 'job_title' => $j, 'email' => strtolower($f) . '@' . explode('@', (string) $cu['email'])[1], 'phone' => '+91 99' . random_int(10000000, 99999999), 'is_primary' => 1]);
        }

        $statuses = ['New', 'Contacted', 'Qualified', 'Proposal Sent', 'Negotiation', 'New', 'Contacted', 'Lost'];
        $sources = ['Website', 'LinkedIn', 'Referral', 'Google Ads', 'Upwork', 'Cold Call', 'Website', 'Email Campaign'];
        foreach ([['Arjun Nair', 'Nair Textiles'], ['Sara Thomas', 'CloudKitchen Co'], ['Imran Khan', 'FinEdge Capital'], ['Meera Joshi', 'Joshi Architects'], ['Daniel Brooks', 'Brooks & Co (UK)'], ['Kavya Reddy', 'Reddy Pharma'], ['Rohit Verma', 'UrbanFit Gyms'], ['Li Wei', 'Pacific Traders']] as $i => [$n, $co]) {
            $rec('leads', ['name' => $n, 'company_name' => $co, 'email' => strtolower(str_replace(' ', '.', $n)) . '@example.com', 'phone' => '+91 97' . random_int(10000000, 99999999), 'status' => $statuses[$i], 'source' => $sources[$i], 'rating' => ['Hot', 'Warm', 'Cold'][$i % 3], 'estimated_value' => [150000, 90000, 400000, 60000, 250000, 180000, 75000, 120000][$i], 'industry' => 'Information Technology', 'follow_up_date' => date('Y-m-d', strtotime('+' . ($i - 2) . ' days')), 'tags' => $i % 2 ? 'website, priority' : 'app', 'assigned_to' => $adminId]);
        }

        $stages = $db->all('SELECT s.* FROM pipeline_stages s JOIN pipelines p ON p.id = s.pipeline_id WHERE p.company_id = ? AND p.is_default = 1 ORDER BY s.sort', [$companyId]);
        foreach ([['E-commerce replatform', 0, 1, 480000], ['Patient portal app', 2, 2, 720000], ['Fleet tracking dashboard', 1, 3, 350000], ['Property listing website', 3, 4, 140000], ['LMS mobile app', 4, 0, 560000], ['SEO retainer — annual', 0, 4, 300000]] as [$n, $ci, $si, $amt]) {
            $rec('deals', ['name' => $n, 'customer_id' => $customers[$ci]['id'], 'contact_id' => $contacts[$ci]['id'], 'stage_id' => $stages[$si]['id'], 'amount' => $amt, 'expected_close' => date('Y-m-d', strtotime('+' . (10 + $si * 7) . ' days')), 'type' => 'New Business', 'source' => 'Referral', 'next_step' => 'Share revised estimate', 'assigned_to' => $adminId]);
        }

        $p1 = $rec('projects', ['name' => 'Acme storefront rebuild', 'code' => 'ACME-01', 'customer_id' => $customers[0]['id'], 'status' => 'In Progress', 'billing_type' => 'Hourly', 'hourly_rate' => 1800, 'budget' => 600000, 'start_date' => date('Y-m-d', strtotime('-20 days')), 'due_date' => date('Y-m-d', strtotime('+40 days')), 'priority' => 'High', 'assigned_to' => $adminId]);
        $p2 = $rec('projects', ['name' => 'Greenleaf patient portal', 'code' => 'GLH-02', 'customer_id' => $customers[2]['id'], 'status' => 'Not Started', 'billing_type' => 'Fixed', 'budget' => 720000, 'start_date' => date('Y-m-d', strtotime('+7 days')), 'due_date' => date('Y-m-d', strtotime('+90 days')), 'assigned_to' => $adminId]);
        $m1 = $rec('milestones', ['project_id' => $p1['id'], 'name' => 'Design sign-off', 'due_date' => date('Y-m-d', strtotime('+5 days')), 'amount' => 150000]);
        $taskDefs = [['Discovery workshop', 'Done', 'High', -10], ['Information architecture', 'Done', 'Medium', -5], ['Homepage UI design', 'In Review', 'High', 2], ['Product listing API', 'In Progress', 'High', 6], ['Payment gateway integration', 'To Do', 'Urgent', 12], ['Performance audit', 'To Do', 'Low', 20], ['UAT with client', 'Blocked', 'Medium', 25]];
        $tasks = [];
        foreach ($taskDefs as $i => [$t, $s, $pr, $d]) {
            $tasks[] = $rec('tasks', ['title' => $t, 'project_id' => $p1['id'], 'milestone_id' => $i < 3 ? $m1['id'] : null, 'status' => $s, 'priority' => $pr, 'due_date' => date('Y-m-d', strtotime("$d days")), 'estimate_hours' => [6, 10, 16, 24, 12, 8, 6][$i], 'is_billable' => 1, 'assigned_to' => $adminId]);
        }
        $rec('tasks', ['title' => 'Prepare SOW and timeline', 'project_id' => $p2['id'], 'status' => 'To Do', 'priority' => 'High', 'due_date' => date('Y-m-d', strtotime('+3 days')), 'assigned_to' => $adminId]);
        foreach ([[0, 360, -9], [1, 480, -6], [2, 300, -2], [3, 420, -1]] as [$ti, $mins, $d]) {
            $rec('time_entries', ['project_id' => $p1['id'], 'task_id' => $tasks[$ti]['id'], 'entry_date' => date('Y-m-d', strtotime("$d days")), 'minutes' => $mins, 'is_billable' => 1, 'description' => $tasks[$ti]['title'], 'assigned_to' => $adminId]);
        }

        foreach ([['Checkout page shows blank screen on Safari', 0, 'Urgent', 'Bug', 'Open'], ['Request: add GST number on invoices', 1, 'Medium', 'Feature Request', 'In Progress'], ['SSL certificate renewal', 3, 'High', 'Maintenance', 'Resolved'], ['How do I export patient reports?', 2, 'Low', 'Question', 'Waiting on Customer']] as [$s, $ci, $pr, $ty, $st]) {
            $rec('tickets', ['subject' => $s, 'customer_id' => $customers[$ci]['id'], 'contact_id' => $contacts[$ci]['id'], 'priority' => $pr, 'type' => $ty, 'status' => $st, 'channel' => 'Email', 'description' => 'Reported by the customer via e-mail.', 'assigned_to' => $adminId]);
        }

        $inv = SalesService::save('invoice', null, ['customer_id' => $customers[0]['id'], 'contact_id' => $contacts[0]['id'], 'project_id' => $p1['id'], 'subject' => 'Storefront rebuild — milestone 1', 'issue_date' => date('Y-m-d', strtotime('-12 days')), 'due_date' => date('Y-m-d', strtotime('+3 days')), 'items' => [['product_id' => $products[0]['id'], 'description' => 'Website design & development — milestone 1', 'quantity' => 1, 'unit_price' => 150000, 'tax_id' => $tax]]]);
        SalesService::setStatus('invoice', (int) $inv['id'], 'Sent');
        RecordService::for('payments')->create(['invoice_id' => $inv['id'], 'amount' => 100000, 'paid_on' => date('Y-m-d', strtotime('-4 days')), 'method' => 'Bank Transfer', 'reference' => 'UTR' . random_int(100000, 999999)], ['silent' => true, 'skip_permission' => true]);
        $inv2 = SalesService::save('invoice', null, ['customer_id' => $customers[1]['id'], 'subject' => 'Managed hosting — quarterly', 'issue_date' => date('Y-m-d', strtotime('-40 days')), 'due_date' => date('Y-m-d', strtotime('-25 days')), 'items' => [['product_id' => $products[4]['id'], 'description' => 'Managed cloud hosting', 'quantity' => 3, 'unit' => 'month', 'unit_price' => 4500, 'tax_id' => $tax]]]);
        SalesService::setStatus('invoice', (int) $inv2['id'], 'Sent');
        $inv3 = SalesService::save('invoice', null, ['customer_id' => $customers[3]['id'], 'subject' => 'SEO retainer', 'issue_date' => date('Y-m-d', strtotime('-60 days')), 'due_date' => date('Y-m-d', strtotime('-45 days')), 'items' => [['product_id' => $products[3]['id'], 'description' => 'SEO retainer', 'quantity' => 1, 'unit' => 'month', 'unit_price' => 25000, 'tax_id' => $tax]]]);
        SalesService::setStatus('invoice', (int) $inv3['id'], 'Sent');
        RecordService::for('payments')->create(['invoice_id' => $inv3['id'], 'amount' => 29500, 'paid_on' => date('Y-m-d', strtotime('-50 days')), 'method' => 'UPI', 'reference' => 'UPI' . random_int(100000, 999999)], ['silent' => true, 'skip_permission' => true]);
        SalesService::save('quote', null, ['customer_id' => $customers[2]['id'], 'contact_id' => $contacts[2]['id'], 'subject' => 'Patient portal — phase 1', 'valid_until' => date('Y-m-d', strtotime('+15 days')), 'status' => 'Sent', 'items' => [['product_id' => $products[1]['id'], 'description' => 'Custom web application — 3 sprints', 'quantity' => 3, 'unit_price' => 240000, 'tax_id' => $tax], ['description' => 'Project management & QA', 'quantity' => 1, 'unit_price' => 60000, 'tax_id' => $tax]], 'discount_type' => 'percent', 'discount_value' => 5]);

        $dept = $rec('departments', ['name' => 'Engineering', 'head_user_id' => $adminId]);
        $rec('departments', ['name' => 'Sales & Marketing']);
        $e1 = $rec('employees', ['first_name' => 'Aditya', 'last_name' => 'Rao', 'employee_code' => 'EMP-001', 'email' => 'aditya@example.com', 'department_id' => $dept['id'], 'designation' => 'Senior Developer', 'joining_date' => date('Y-m-d', strtotime('-400 days')), 'leave_balance' => 12, 'skills' => 'PHP, Laravel, MySQL']);
        $rec('employees', ['first_name' => 'Fatima', 'last_name' => 'Shaikh', 'employee_code' => 'EMP-002', 'email' => 'fatima@example.com', 'department_id' => $dept['id'], 'designation' => 'UI/UX Designer', 'joining_date' => date('Y-m-d', strtotime('-200 days')), 'leave_balance' => 10, 'skills' => 'Figma, Tailwind']);
        $rec('leave_requests', ['employee_id' => $e1['id'], 'type' => 'Casual', 'start_date' => date('Y-m-d', strtotime('next monday')), 'end_date' => date('Y-m-d', strtotime('next monday +1 day')), 'reason' => 'Family function']);
        $rec('expenses', ['title' => 'AWS invoice', 'category' => 'Hosting & Domains', 'amount' => 18500, 'expense_date' => date('Y-m-d', strtotime('-3 days')), 'vendor' => 'Amazon Web Services', 'project_id' => $p1['id'], 'status' => 'Approved']);
        $rec('expenses', ['title' => 'Figma team plan', 'category' => 'Software & SaaS', 'amount' => 4200, 'expense_date' => date('Y-m-d', strtotime('-9 days')), 'vendor' => 'Figma']);
        $rec('contracts', ['title' => 'Acme — Master Services Agreement', 'customer_id' => $customers[0]['id'], 'type' => 'MSA', 'value' => 1200000, 'start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d', strtotime('+335 days')), 'status' => 'Active']);
        $rec('kb_articles', ['title' => 'How to reset your client portal password', 'category' => 'How-to', 'status' => 'Published', 'body' => "1. Open the login page.\n2. Click \"Forgot password\".\n3. Enter your e-mail and follow the link we send you."]);
        $rec('events', ['title' => 'Sprint review with Acme', 'type' => 'Meeting', 'starts_at' => to_local_input(gmdate('Y-m-d H:i:s', strtotime('tomorrow 05:30'))), 'meeting_url' => 'https://meet.example.com/acme', 'related_entity' => 'customers', 'related_id' => $customers[0]['id'], 'reminder_minutes' => '30']);
        $rec('events', ['title' => 'Discovery call — Reddy Pharma', 'type' => 'Call', 'starts_at' => to_local_input(gmdate('Y-m-d H:i:s', strtotime('+2 days 08:30'))), 'reminder_minutes' => '10']);
        Settings::set('demo.installed', true, $companyId);
    }
}

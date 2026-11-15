<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Key/value settings. company_id = 0 holds global settings; company rows override them.
 * Keys listed in SECRET_KEYS are encrypted at rest.
 */
final class Settings
{
    private static ?array $cache = null;
    private static int $cacheCompany = -1;

    public const SECRET_KEYS = [
        'mail.smtp_password', 'storage.s3.secret', 'storage.wasabi.secret', 'storage.s3c.secret',
        'storage.ftp.password', 'storage.sftp.password', 'storage.sftp.private_key', 'cron.key',
        'pay.razorpay.key_secret', 'pay.razorpay.webhook_secret', 'pay.stripe.secret_key', 'pay.stripe.webhook_secret', 'pay.paypal.client_secret',
        'wa.meta.token', 'wa.twilio.token', 'wa.gupshup.api_key', 'ai.anthropic.key', 'ai.openai.key', 'ai.custom.key',
    ];

    public static function defaults(): array
    {
        return [
            'app.name' => 'NextaCRM',
            'app.date_format' => 'd M Y',
            'app.time_format' => 'H:i',
            'app.week_start' => 1,
            'app.per_page' => 25,
            'app.modules_disabled' => [],
            'security.password_min' => 10,
            'security.idle_timeout' => 120,
            'security.session_lifetime' => 720,
            'security.enforce_2fa_admins' => false,
            'security.allowed_upload_ext' => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip,rar,7z,mp4,mp3,psd,ai,fig,sketch,json,xml,odt,ods',
            'security.max_upload_mb' => 25,
            'mail.driver' => 'smtp',
            'mail.from_email' => '',
            'mail.from_name' => 'NextaCRM',
            'mail.smtp_host' => '',
            'mail.smtp_port' => 587,
            'mail.smtp_encryption' => 'tls',
            'mail.smtp_username' => '',
            'mail.smtp_password' => '',
            'storage.driver' => 'local',
            'storage.signed_urls' => false,
            'picklist.lead_status' => ['New', 'Contacted', 'Qualified', 'Proposal Sent', 'Negotiation', 'Converted', 'Lost'],
            'picklist.lead_source' => ['Website', 'Referral', 'LinkedIn', 'Google Ads', 'Facebook', 'Cold Call', 'Email', 'Email Campaign', 'WhatsApp', 'Client portal', 'Event', 'Partner', 'Upwork', 'Other'],
            'picklist.industry' => ['Information Technology', 'E-commerce', 'Healthcare', 'Education', 'Finance', 'Real Estate', 'Manufacturing', 'Retail', 'Travel & Hospitality', 'Media', 'Non-profit', 'Government', 'Other'],
            'picklist.customer_type' => ['Prospect', 'Customer', 'Partner', 'Vendor', 'Reseller'],
            'picklist.task_status' => ['To Do', 'In Progress', 'In Review', 'Blocked', 'Done'],
            'picklist.project_status' => ['Not Started', 'In Progress', 'On Hold', 'Completed', 'Cancelled'],
            'picklist.ticket_status' => ['Open', 'In Progress', 'Waiting on Customer', 'Resolved', 'Closed'],
            'picklist.ticket_type' => ['Question', 'Bug', 'Feature Request', 'Incident', 'Maintenance', 'Billing'],
            'picklist.expense_category' => ['Software & SaaS', 'Hosting & Domains', 'Hardware', 'Travel', 'Marketing', 'Office', 'Freelancers', 'Salaries', 'Taxes', 'Other'],
            'picklist.lost_reason' => ['Price too high', 'Chose competitor', 'No budget', 'No response', 'Timing', 'Requirements mismatch', 'Other'],
            'picklist.leave_type' => ['Casual', 'Sick', 'Earned', 'Unpaid', 'Work From Home', 'Comp Off'],
            'picklist.department' => [],
            'picklist.payment_method' => ['Bank Transfer', 'UPI', 'Card', 'Cash', 'Cheque', 'PayPal', 'Stripe', 'Razorpay', 'Other'],
            'picklist.kb_category' => ['Getting Started', 'How-to', 'Troubleshooting', 'Policies', 'FAQ'],
            'sla.policies' => [
                'Urgent' => ['response' => 1, 'resolution' => 8],
                'High' => ['response' => 4, 'resolution' => 24],
                'Medium' => ['response' => 8, 'resolution' => 72],
                'Low' => ['response' => 24, 'resolution' => 120],
            ],
            'lead_scoring.rules' => [
                ['field' => 'email', 'op' => 'filled', 'value' => '', 'points' => 10],
                ['field' => 'phone', 'op' => 'filled', 'value' => '', 'points' => 10],
                ['field' => 'company_name', 'op' => 'filled', 'value' => '', 'points' => 5],
                ['field' => 'estimated_value', 'op' => 'gt', 'value' => '100000', 'points' => 20],
                ['field' => 'source', 'op' => 'eq', 'value' => 'Referral', 'points' => 15],
                ['field' => 'rating', 'op' => 'eq', 'value' => 'Hot', 'points' => 25],
            ],
        ];
    }

    private static function load(): array
    {
        $cid = Auth::check() ? Auth::companyId() : 0;
        if (self::$cache !== null && self::$cacheCompany === $cid) {
            return self::$cache;
        }
        $values = self::defaults();
        try {
            $rows = App::db()->all('SELECT company_id, k, v FROM settings WHERE company_id IN (0, ?) ORDER BY company_id', [$cid]);
            foreach ($rows as $row) {
                $values[$row['k']] = json_decode((string) $row['v'], true);
            }
        } catch (\Throwable) {
            // Database not ready (installer). Use defaults.
        }
        self::$cacheCompany = $cid;
        return self::$cache = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::load();
        if (!array_key_exists($key, $all)) {
            return $default;
        }
        $v = $all[$key];
        if (in_array($key, self::SECRET_KEYS, true) && is_string($v)) {
            return Crypto::decrypt($v);
        }
        return $v;
    }

    public static function set(string $key, mixed $value, int $companyId = 0): void
    {
        if (in_array($key, self::SECRET_KEYS, true) && is_string($value) && $value !== '') {
            $value = Crypto::encrypt($value);
        }
        $db = App::db();
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $exists = $db->value('SELECT id FROM settings WHERE company_id = ? AND k = ?', [$companyId, $key]);
        if ($exists) {
            $db->update('settings', ['v' => $json, 'updated_at' => DB::now()], ['id' => $exists]);
        } else {
            $db->insert('settings', ['company_id' => $companyId, 'k' => $key, 'v' => $json, 'updated_at' => DB::now()]);
        }
        self::$cache = null;
    }

    public static function setMany(array $values, int $companyId = 0): void
    {
        foreach ($values as $k => $v) {
            self::set($k, $v, $companyId);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}

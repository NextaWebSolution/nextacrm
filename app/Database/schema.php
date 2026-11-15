<?php
declare(strict_types=1);
defined('NX_ROOT') || exit;

use App\Core\Schema;
use App\Core\SchemaTable as T;

/**
 * NextaCRM database schema (v1.0). Single source of truth; DATABASE_DESIGN.md is generated from it.
 * Money: DECIMAL(15,2). Datetimes: UTC. Dates: local calendar dates.
 */
return static function (Schema $s): void {

    // ───────────────────────────── Platform ─────────────────────────────
    $s->create('companies', function (T $t) {
        $t->id();
        $t->string('name');
        $t->string('legal_name')->nullable();
        $t->string('email')->nullable();
        $t->string('phone', 50)->nullable();
        $t->string('website')->nullable();
        $t->text('address');
        $t->string('city', 100)->nullable();
        $t->string('state', 100)->nullable();
        $t->string('country', 100)->nullable();
        $t->string('postal_code', 20)->nullable();
        $t->string('tax_id', 50)->nullable();
        $t->string('currency', 3)->default('INR');
        $t->string('timezone', 64)->default('Asia/Kolkata');
        $t->foreignId('logo_file_id');
        $t->string('brand_color', 9)->default('#4f46e5');
        $t->string('invoice_prefix', 20)->default('INV-');
        $t->int('invoice_next')->default(1);
        $t->string('quote_prefix', 20)->default('QT-');
        $t->int('quote_next')->default(1);
        $t->string('ticket_prefix', 20)->default('TKT-');
        $t->int('ticket_next')->default(1);
        $t->int('payment_terms_days')->default(15);
        $t->text('invoice_terms');
        $t->text('invoice_footer');
        $t->text('bank_details');
        $t->bool('is_active')->default(1);
        $t->timestamps();
    });

    $s->create('roles', function (T $t) {
        $t->id();
        $t->string('name', 100);
        $t->string('description')->nullable();
        $t->bool('is_admin');
        $t->bool('is_system');
        $t->json('permissions');
        $t->timestamps();
    });

    $s->create('users', function (T $t) {
        $t->id();
        $t->string('name', 150);
        $t->string('email');
        $t->string('password')->nullable();
        $t->foreignId('role_id');
        $t->string('status', 20)->default('active');
        $t->string('phone', 50)->nullable();
        $t->string('job_title', 150)->nullable();
        $t->foreignId('avatar_file_id');
        $t->string('timezone', 64)->default('Asia/Kolkata');
        $t->string('locale', 10)->default('en');
        $t->foreignId('default_company_id');
        $t->json('preferences');
        $t->bool('two_factor_enabled');
        $t->text('two_factor_secret');
        $t->bigInt('two_factor_last_step')->nullable();
        $t->text('recovery_codes');
        $t->datetime('email_verified_at');
        $t->datetime('last_login_at');
        $t->string('last_login_ip', 45)->nullable();
        $t->timestamps();
        $t->datetime('deleted_at');
        $t->unique(['email']);
        $t->index(['role_id']);
    });

    $s->create('teams', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 150);
        $t->string('description')->nullable();
        $t->timestamps();
    });
    $s->create('team_user', function (T $t) {
        $t->id();
        $t->foreignId('team_id');
        $t->foreignId('user_id');
        $t->unique(['team_id', 'user_id']);
        $t->index(['user_id']);
    });
    $s->create('company_user', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('user_id');
        $t->unique(['company_id', 'user_id']);
        $t->index(['user_id']);
    });

    $s->create('user_sessions', function (T $t) {
        $t->id();
        $t->foreignId('user_id');
        $t->string('token_hash', 64);
        $t->string('ip', 45)->nullable();
        $t->string('user_agent')->nullable();
        $t->string('ua_hash', 64)->nullable();
        $t->datetime('created_at');
        $t->datetime('last_seen_at');
        $t->datetime('revoked_at');
        $t->unique(['token_hash']);
        $t->index(['user_id', 'revoked_at']);
    });

    $s->create('login_attempts', function (T $t) {
        $t->id();
        $t->string('email');
        $t->string('ip', 45);
        $t->bool('success');
        $t->datetime('created_at');
        $t->index(['email', 'created_at']);
        $t->index(['ip', 'created_at']);
    });

    $s->create('password_resets', function (T $t) {
        $t->id();
        $t->foreignId('user_id');
        $t->string('token_hash', 64);
        $t->string('purpose', 20)->default('reset');
        $t->datetime('expires_at');
        $t->datetime('used_at');
        $t->datetime('created_at');
        $t->unique(['token_hash']);
    });

    $s->create('api_tokens', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('user_id');
        $t->string('name', 100);
        $t->string('token_hash', 64);
        $t->string('token_prefix', 12);
        $t->string('scope', 10)->default('read');
        $t->datetime('expires_at');
        $t->datetime('last_used_at');
        $t->string('last_used_ip', 45)->nullable();
        $t->datetime('revoked_at');
        $t->datetime('created_at');
        $t->unique(['token_hash']);
    });

    $s->create('settings', function (T $t) {
        $t->id();
        $t->bigInt('company_id')->default(0);
        $t->string('k', 150);
        $t->longText('v');
        $t->datetime('updated_at');
        $t->unique(['company_id', 'k']);
    });

    $s->create('audit_logs', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('user_id');
        $t->string('action', 64);
        $t->string('entity', 64)->nullable();
        $t->bigInt('record_id')->nullable();
        $t->json('changes');
        $t->string('ip', 45)->nullable();
        $t->string('user_agent')->nullable();
        $t->datetime('created_at');
        $t->index(['entity', 'record_id']);
        $t->index(['company_id', 'created_at']);
        $t->index(['user_id']);
    });

    $s->create('rate_limits', function (T $t) {
        $t->id();
        $t->string('k', 64);
        $t->int('hits')->default(0);
        $t->bigInt('reset_at')->default(0);
        $t->unique(['k']);
    });

    $s->create('notifications', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('user_id');
        $t->string('type', 50);
        $t->string('title');
        $t->text('body');
        $t->string('url', 500)->nullable();
        $t->string('entity', 64)->nullable();
        $t->bigInt('record_id')->nullable();
        $t->datetime('read_at');
        $t->datetime('created_at');
        $t->index(['user_id', 'read_at']);
    });

    $s->create('activities', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('entity', 64);
        $t->bigInt('record_id');
        $t->string('type', 30)->default('note');
        $t->string('subject')->nullable();
        $t->text('body');
        $t->json('meta');
        $t->foreignId('user_id');
        $t->datetime('occurred_at');
        $t->datetime('created_at');
        $t->datetime('deleted_at');
        $t->index(['entity', 'record_id']);
        $t->index(['company_id', 'created_at']);
    });

    $s->create('folders', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('parent_id');
        $t->string('name', 150);
        $t->foreignId('created_by');
        $t->datetime('created_at');
        $t->index(['company_id', 'parent_id']);
    });

    $s->create('files', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('folder_id');
        $t->string('name');
        $t->string('path', 500);
        $t->string('driver', 20);
        $t->string('mime', 150)->nullable();
        $t->string('ext', 20)->nullable();
        $t->bigInt('size')->default(0);
        $t->string('checksum', 64)->nullable();
        $t->string('entity', 64)->nullable();
        $t->bigInt('record_id')->nullable();
        $t->string('description')->nullable();
        $t->bool('is_public');
        $t->foreignId('uploaded_by');
        $t->datetime('created_at');
        $t->datetime('deleted_at');
        $t->index(['entity', 'record_id']);
        $t->index(['company_id', 'folder_id']);
    });

    $s->create('saved_views', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('user_id');
        $t->string('entity', 64);
        $t->string('name', 100);
        $t->json('config');
        $t->bool('is_shared');
        $t->int('sort')->default(0);
        $t->timestamps();
        $t->index(['company_id', 'entity']);
    });

    $s->create('custom_fields', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('entity', 64);
        $t->string('name', 64);
        $t->string('label', 100);
        $t->string('type', 20);
        $t->json('options');
        $t->bool('required');
        $t->bool('show_in_list');
        $t->int('sort')->default(0);
        $t->datetime('created_at');
        $t->unique(['company_id', 'entity', 'name']);
    });

    $s->create('workflows', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 150);
        $t->string('entity', 64);
        $t->string('trigger_type', 30);
        $t->json('trigger_config');
        $t->json('conditions');
        $t->json('actions');
        $t->bool('is_active')->default(1);
        $t->int('run_count')->default(0);
        $t->datetime('last_run_at');
        $t->string('webhook_token', 64)->nullable();
        $t->foreignId('created_by');
        $t->timestamps();
        $t->index(['company_id', 'entity', 'trigger_type']);
    });

    $s->create('workflow_runs', function (T $t) {
        $t->id();
        $t->foreignId('workflow_id');
        $t->string('entity', 64)->nullable();
        $t->bigInt('record_id')->nullable();
        $t->string('status', 20);
        $t->json('log');
        $t->datetime('created_at');
        $t->index(['workflow_id', 'created_at']);
        $t->index(['workflow_id', 'record_id']);
    });

    $s->create('webhooks', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 150);
        $t->string('url', 500);
        $t->text('secret');
        $t->json('events');
        $t->bool('is_active')->default(1);
        $t->int('failure_count')->default(0);
        $t->int('last_status')->nullable();
        $t->foreignId('created_by');
        $t->timestamps();
    });

    $s->create('webhook_deliveries', function (T $t) {
        $t->id();
        $t->foreignId('webhook_id');
        $t->string('event', 64);
        $t->longText('payload');
        $t->string('status', 20)->default('pending');
        $t->int('attempts')->default(0);
        $t->int('response_code')->nullable();
        $t->text('response_body');
        $t->datetime('next_attempt_at');
        $t->datetime('delivered_at');
        $t->datetime('created_at');
        $t->index(['status', 'next_attempt_at']);
        $t->index(['webhook_id', 'created_at']);
    });

    $s->create('email_queue', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('to_email');
        $t->string('subject', 255);
        $t->longText('body');
        $t->longText('attachments');
        $t->string('reply_to')->nullable();
        $t->string('status', 20)->default('pending');
        $t->int('attempts')->default(0);
        $t->string('error', 500)->nullable();
        $t->datetime('created_at');
        $t->datetime('sent_at');
        $t->index(['status']);
    });

    $s->create('lead_forms', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 150);
        $t->string('token', 64);
        $t->json('fields');
        $t->foreignId('assigned_to');
        $t->string('default_status', 50)->nullable();
        $t->string('default_source', 50)->nullable();
        $t->string('success_message', 500)->nullable();
        $t->string('redirect_url', 500)->nullable();
        $t->bool('is_active')->default(1);
        $t->int('submissions')->default(0);
        $t->foreignId('created_by');
        $t->timestamps();
        $t->unique(['token']);
    });

    $s->create('currencies', function (T $t) {
        $t->id();
        $t->string('code', 3);
        $t->string('name', 60);
        $t->decimal('rate', 18, 6)->default(1);
        $t->bool('is_active')->default(1);
        $t->datetime('updated_at');
        $t->unique(['code']);
    });

    $s->create('taxes', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 60);
        $t->decimal('rate', 7, 3)->default(0);
        $t->bool('is_default');
        $t->datetime('created_at');
        $t->index(['company_id']);
    });

    $s->create('pipelines', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 100);
        $t->bool('is_default');
        $t->int('sort')->default(0);
        $t->datetime('created_at');
    });

    $s->create('pipeline_stages', function (T $t) {
        $t->id();
        $t->foreignId('pipeline_id');
        $t->string('name', 100);
        $t->int('probability')->default(0);
        $t->string('color', 20)->default('slate');
        $t->int('sort')->default(0);
        $t->bool('is_won');
        $t->bool('is_lost');
        $t->index(['pipeline_id', 'sort']);
    });

    $s->create('migrations', function (T $t) {
        $t->id();
        $t->string('name', 150);
        $t->datetime('ran_at');
        $t->unique(['name']);
    });

    // ───────────────────────────── CRM & Sales ─────────────────────────────
    $s->create('leads', function (T $t) {
        $t->id();
        $t->string('name', 150);
        $t->string('company_name', 191)->nullable();
        $t->string('email')->nullable();
        $t->string('phone', 50)->nullable();
        $t->string('whatsapp', 50)->nullable();
        $t->string('job_title', 150)->nullable();
        $t->string('website')->nullable();
        $t->string('source', 50)->nullable();
        $t->string('status', 50)->default('New');
        $t->string('rating', 20)->nullable();
        $t->string('qualification', 20)->nullable();
        $t->decimal('estimated_value')->nullable();
        $t->string('currency', 3)->nullable();
        $t->string('industry', 100)->nullable();
        $t->string('company_size', 30)->nullable();
        $t->decimal('annual_revenue')->nullable();
        $t->text('address');
        $t->string('city', 100)->nullable();
        $t->string('state', 100)->nullable();
        $t->string('country', 100)->nullable();
        $t->date('follow_up_date');
        $t->int('score')->default(0);
        $t->string('tags', 500)->nullable();
        $t->text('description');
        $t->string('utm_source', 100)->nullable();
        $t->string('utm_medium', 100)->nullable();
        $t->string('utm_campaign', 150)->nullable();
        $t->string('lost_reason', 100)->nullable();
        $t->datetime('converted_at');
        $t->foreignId('customer_id');
        $t->foreignId('contact_id');
        $t->foreignId('deal_id');
        $t->record();
        $t->index(['company_id', 'status']);
        $t->index(['email']);
        $t->index(['phone']);
    });

    $s->create('customers', function (T $t) {
        $t->id();
        $t->string('name');
        $t->string('type', 30)->default('Customer');
        $t->string('email')->nullable();
        $t->string('phone', 50)->nullable();
        $t->string('website')->nullable();
        $t->string('industry', 100)->nullable();
        $t->string('tax_id', 50)->nullable();
        $t->string('currency', 3)->nullable();
        $t->text('billing_address');
        $t->string('city', 100)->nullable();
        $t->string('state', 100)->nullable();
        $t->string('country', 100)->nullable();
        $t->string('postal_code', 20)->nullable();
        $t->string('company_size', 30)->nullable();
        $t->decimal('annual_revenue')->nullable();
        $t->string('source', 50)->nullable();
        $t->string('tags', 500)->nullable();
        $t->text('description');
        $t->record();
        $t->index(['company_id', 'name']);
    });

    $s->create('contacts', function (T $t) {
        $t->id();
        $t->foreignId('customer_id');
        $t->string('first_name', 100);
        $t->string('last_name', 100)->nullable();
        $t->string('name', 200);
        $t->string('email')->nullable();
        $t->string('phone', 50)->nullable();
        $t->string('mobile', 50)->nullable();
        $t->string('job_title', 150)->nullable();
        $t->string('department', 100)->nullable();
        $t->bool('is_primary');
        $t->date('birthday');
        $t->string('linkedin')->nullable();
        $t->text('address');
        $t->string('city', 100)->nullable();
        $t->string('country', 100)->nullable();
        $t->string('tags', 500)->nullable();
        $t->date('next_follow_up');
        $t->text('description');
        $t->bool('portal_enabled');
        $t->string('portal_password')->nullable();
        $t->datetime('portal_last_login');
        $t->record();
        $t->index(['customer_id']);
        $t->index(['email']);
    });

    $s->create('deals', function (T $t) {
        $t->id();
        $t->string('name');
        $t->foreignId('customer_id');
        $t->foreignId('contact_id');
        $t->foreignId('lead_id');
        $t->foreignId('pipeline_id');
        $t->foreignId('stage_id');
        $t->decimal('amount')->default(0);
        $t->string('currency', 3)->nullable();
        $t->int('probability')->default(0);
        $t->date('expected_close');
        $t->datetime('closed_at');
        $t->string('status', 10)->default('open');
        $t->string('type', 30)->nullable();
        $t->bool('is_recurring');
        $t->string('recurring_interval', 20)->nullable();
        $t->string('lost_reason', 100)->nullable();
        $t->string('competitor', 150)->nullable();
        $t->string('source', 50)->nullable();
        $t->string('next_step')->nullable();
        $t->string('tags', 500)->nullable();
        $t->text('description');
        $t->datetime('stage_changed_at');
        $t->int('sort')->default(0);
        $t->record();
        $t->index(['company_id', 'pipeline_id', 'stage_id']);
        $t->index(['customer_id']);
    });

    $s->create('products', function (T $t) {
        $t->id();
        $t->string('name');
        $t->string('sku', 64)->nullable();
        $t->string('type', 20)->default('Service');
        $t->string('unit', 20)->default('unit');
        $t->decimal('price')->default(0);
        $t->decimal('cost')->nullable();
        $t->foreignId('tax_id');
        $t->string('currency', 3)->nullable();
        $t->string('hsn_sac', 20)->nullable();
        $t->text('description');
        $t->bool('is_active')->default(1);
        $t->record(false);
    });

    $docColumns = function (T $t) {
        $t->string('number', 50);
        $t->foreignId('customer_id');
        $t->foreignId('contact_id');
        $t->foreignId('project_id');
        $t->string('subject')->nullable();
        $t->string('status', 30)->default('Draft');
        $t->date('issue_date');
        $t->string('currency', 3)->default('INR');
        $t->decimal('exchange_rate', 18, 6)->default(1);
        $t->decimal('subtotal')->default(0);
        $t->string('discount_type', 10)->default('percent');
        $t->decimal('discount_value')->default(0);
        $t->decimal('discount_total')->default(0);
        $t->decimal('tax_total')->default(0);
        $t->decimal('adjustment')->default(0);
        $t->decimal('total')->default(0);
        $t->text('notes');
        $t->text('terms');
        $t->string('public_token', 64)->nullable();
        $t->datetime('sent_at');
    };

    $s->create('quotes', function (T $t) use ($docColumns) {
        $t->id();
        $docColumns($t);
        $t->foreignId('deal_id');
        $t->date('valid_until');
        $t->datetime('accepted_at');
        $t->string('accepted_by_name', 150)->nullable();
        $t->foreignId('invoice_id');
        $t->string('approval_status', 20)->nullable();
        $t->record();
        $t->index(['company_id', 'status']);
        $t->index(['customer_id']);
        $t->index(['public_token']);
    });

    $s->create('invoices', function (T $t) use ($docColumns) {
        $t->id();
        $docColumns($t);
        $t->foreignId('quote_id');
        $t->date('due_date');
        $t->decimal('amount_paid')->default(0);
        $t->decimal('balance')->default(0);
        $t->datetime('paid_at');
        $t->bool('is_recurring');
        $t->string('recurring_interval', 20)->nullable();
        $t->date('recurring_next_date');
        $t->date('recurring_end_date');
        $t->foreignId('recurring_parent_id');
        $t->record();
        $t->index(['company_id', 'status']);
        $t->index(['customer_id']);
        $t->index(['due_date']);
        $t->index(['public_token']);
    });

    $s->create('line_items', function (T $t) {
        $t->id();
        $t->string('parent_type', 20);
        $t->foreignId('parent_id');
        $t->foreignId('product_id');
        $t->text('description');
        $t->decimal('quantity', 12, 3)->default(1);
        $t->string('unit', 20)->nullable();
        $t->decimal('unit_price')->default(0);
        $t->decimal('discount_percent', 7, 3)->default(0);
        $t->foreignId('tax_id');
        $t->decimal('tax_rate', 7, 3)->default(0);
        $t->decimal('tax_amount')->default(0);
        $t->decimal('line_total')->default(0);
        $t->text('time_entry_ids');
        $t->int('sort')->default(0);
        $t->index(['parent_type', 'parent_id']);
    });

    $s->create('payments', function (T $t) {
        $t->id();
        $t->foreignId('invoice_id');
        $t->foreignId('customer_id');
        $t->decimal('amount')->default(0);
        $t->string('currency', 3)->nullable();
        $t->date('paid_on');
        $t->string('method', 50)->nullable();
        $t->string('reference', 150)->nullable();
        $t->text('notes');
        $t->string('gateway', 20)->nullable();
        $t->string('gateway_payment_id', 100)->nullable();
        $t->index(['gateway', 'gateway_payment_id']);
        $t->record(false);
        $t->index(['invoice_id']);
    });

    $s->create('expenses', function (T $t) {
        $t->id();
        $t->string('title');
        $t->string('category', 100)->nullable();
        $t->decimal('amount')->default(0);
        $t->decimal('tax_amount')->default(0);
        $t->string('currency', 3)->nullable();
        $t->date('expense_date');
        $t->string('vendor', 150)->nullable();
        $t->string('payment_method', 50)->nullable();
        $t->foreignId('customer_id');
        $t->foreignId('project_id');
        $t->bool('is_billable');
        $t->string('status', 20)->default('Pending');
        $t->foreignId('approved_by');
        $t->datetime('approved_at');
        $t->foreignId('invoice_id');
        $t->text('notes');
        $t->string('approval_status', 20)->nullable();
        $t->record();
        $t->index(['company_id', 'expense_date']);
    });

    $s->create('contracts', function (T $t) {
        $t->id();
        $t->string('title');
        $t->foreignId('customer_id');
        $t->foreignId('contact_id');
        $t->foreignId('project_id');
        $t->string('type', 50)->nullable();
        $t->decimal('value')->nullable();
        $t->string('currency', 3)->nullable();
        $t->date('start_date');
        $t->date('end_date');
        $t->int('renewal_reminder_days')->default(30);
        $t->string('status', 20)->default('Draft');
        $t->text('description');
        $t->string('public_token', 64)->nullable();
        $t->datetime('renewal_notified_at');
        $t->datetime('signed_at');
        $t->string('signed_by_name', 150)->nullable();
        $t->record();
        $t->index(['end_date']);
    });

    // ───────────────────────────── Delivery ─────────────────────────────
    $s->create('projects', function (T $t) {
        $t->id();
        $t->string('name');
        $t->string('code', 30)->nullable();
        $t->foreignId('customer_id');
        $t->foreignId('deal_id');
        $t->string('status', 30)->default('Not Started');
        $t->string('priority', 20)->default('Medium');
        $t->string('billing_type', 20)->default('Fixed');
        $t->decimal('budget')->nullable();
        $t->decimal('hourly_rate')->nullable();
        $t->string('currency', 3)->nullable();
        $t->date('start_date');
        $t->date('due_date');
        $t->datetime('completed_at');
        $t->int('progress')->default(0);
        $t->string('color', 20)->nullable();
        $t->string('tags', 500)->nullable();
        $t->text('description');
        $t->record();
        $t->index(['company_id', 'status']);
        $t->index(['customer_id']);
    });

    $s->create('project_members', function (T $t) {
        $t->id();
        $t->foreignId('project_id');
        $t->foreignId('user_id');
        $t->string('role', 50)->nullable();
        $t->unique(['project_id', 'user_id']);
        $t->index(['user_id']);
    });

    $s->create('milestones', function (T $t) {
        $t->id();
        $t->foreignId('project_id');
        $t->string('name');
        $t->date('due_date');
        $t->string('status', 20)->default('Open');
        $t->decimal('amount')->nullable();
        $t->text('description');
        $t->int('sort')->default(0);
        $t->record(false);
        $t->index(['project_id']);
    });

    $s->create('tasks', function (T $t) {
        $t->id();
        $t->string('title');
        $t->foreignId('project_id');
        $t->foreignId('milestone_id');
        $t->foreignId('parent_id');
        $t->string('related_entity', 64)->nullable();
        $t->bigInt('related_id')->nullable();
        $t->string('status', 30)->default('To Do');
        $t->string('priority', 20)->default('Medium');
        $t->date('start_date');
        $t->date('due_date');
        $t->decimal('estimate_hours', 8, 2)->nullable();
        $t->decimal('spent_hours', 8, 2)->default(0);
        $t->int('progress')->default(0);
        $t->bool('is_billable');
        $t->string('depends_on', 255)->nullable();
        $t->datetime('completed_at');
        $t->string('tags', 500)->nullable();
        $t->text('description');
        $t->int('sort')->default(0);
        $t->json('checklist');
        $t->string('recurrence', 20)->nullable();
        $t->date('recur_until');
        $t->foreignId('recurred_from');
        $t->record();
        $t->index(['company_id', 'status']);
        $t->index(['project_id']);
        $t->index(['related_entity', 'related_id']);
        $t->index(['due_date']);
    });

    $s->create('time_entries', function (T $t) {
        $t->id();
        $t->foreignId('project_id');
        $t->foreignId('task_id');
        $t->date('entry_date');
        $t->datetime('started_at');
        $t->datetime('ended_at');
        $t->int('minutes')->default(0);
        $t->bool('is_running');
        $t->bool('is_billable')->default(1);
        $t->decimal('hourly_rate')->nullable();
        $t->bool('is_invoiced');
        $t->foreignId('invoice_id');
        $t->text('description');
        $t->record();
        $t->index(['project_id']);
        $t->index(['task_id']);
        $t->index(['assigned_to', 'is_running']);
    });

    // ───────────────────────────── Support ─────────────────────────────
    $s->create('tickets', function (T $t) {
        $t->id();
        $t->string('number', 50);
        $t->string('subject');
        $t->foreignId('customer_id');
        $t->foreignId('contact_id');
        $t->foreignId('project_id');
        $t->string('priority', 20)->default('Medium');
        $t->string('type', 50)->nullable();
        $t->string('status', 30)->default('Open');
        $t->string('channel', 20)->default('Web');
        $t->string('requester_name', 150)->nullable();
        $t->string('requester_email')->nullable();
        $t->text('description');
        $t->datetime('first_response_due');
        $t->datetime('resolution_due');
        $t->datetime('first_responded_at');
        $t->datetime('resolved_at');
        $t->string('sla_status', 20)->nullable();
        $t->int('satisfaction')->nullable();
        $t->string('tags', 500)->nullable();
        $t->record();
        $t->index(['company_id', 'status']);
        $t->index(['customer_id']);
    });

    $s->create('ticket_replies', function (T $t) {
        $t->id();
        $t->foreignId('ticket_id');
        $t->foreignId('user_id');
        $t->text('body');
        $t->bool('is_internal');
        $t->string('author_name', 150)->nullable();
        $t->string('author_email')->nullable();
        $t->datetime('created_at');
        $t->index(['ticket_id']);
    });

    $s->create('kb_articles', function (T $t) {
        $t->id();
        $t->string('title');
        $t->string('slug');
        $t->string('category', 100)->nullable();
        $t->longText('body');
        $t->string('status', 20)->default('Draft');
        $t->int('views')->default(0);
        $t->string('tags', 500)->nullable();
        $t->bool('is_public');
        $t->record(false);
    });

    // ───────────────────────────── Team / HR ─────────────────────────────
    $s->create('departments', function (T $t) {
        $t->id();
        $t->string('name', 150);
        $t->foreignId('head_user_id');
        $t->text('description');
        $t->record(false);
    });

    $s->create('employees', function (T $t) {
        $t->id();
        $t->foreignId('user_id');
        $t->string('employee_code', 30)->nullable();
        $t->string('first_name', 100);
        $t->string('last_name', 100)->nullable();
        $t->string('name', 200);
        $t->string('email')->nullable();
        $t->string('phone', 50)->nullable();
        $t->foreignId('department_id');
        $t->string('designation', 150)->nullable();
        $t->foreignId('manager_id');
        $t->string('employment_type', 30)->default('Full-time');
        $t->string('status', 30)->default('Active');
        $t->date('joining_date');
        $t->date('exit_date');
        $t->date('date_of_birth');
        $t->decimal('salary')->nullable();
        $t->string('salary_currency', 3)->nullable();
        $t->decimal('leave_balance', 6, 1)->default(0);
        $t->text('address');
        $t->string('emergency_contact')->nullable();
        $t->string('skills', 500)->nullable();
        $t->text('notes');
        $t->bool('pf_applicable')->default(1);
        $t->bool('esi_applicable')->default(1);
        $t->bool('pt_applicable')->default(1);
        $t->string('pan', 20)->nullable();
        $t->string('uan', 20)->nullable();
        $t->string('bank_account', 40)->nullable();
        $t->string('bank_ifsc', 15)->nullable();
        $t->record();
        $t->index(['department_id']);
    });

    $s->create('leave_requests', function (T $t) {
        $t->id();
        $t->foreignId('employee_id');
        $t->string('type', 50);
        $t->date('start_date');
        $t->date('end_date');
        $t->decimal('days', 5, 1)->default(1);
        $t->text('reason');
        $t->string('status', 20)->default('Pending');
        $t->foreignId('approved_by');
        $t->datetime('approved_at');
        $t->string('review_note', 500)->nullable();
        $t->record();
        $t->index(['employee_id']);
        $t->index(['start_date']);
    });

    // ───────────────────────────── Calendar ─────────────────────────────
    $s->create('events', function (T $t) {
        $t->id();
        $t->string('title');
        $t->string('type', 30)->default('Meeting');
        $t->datetime('starts_at');
        $t->datetime('ends_at');
        $t->bool('all_day');
        $t->string('location')->nullable();
        $t->string('meeting_url', 500)->nullable();
        $t->string('related_entity', 64)->nullable();
        $t->bigInt('related_id')->nullable();
        $t->string('attendees', 500)->nullable();
        $t->int('reminder_minutes')->nullable();
        $t->bool('reminder_sent');
        $t->text('description');
        $t->record();
        $t->index(['company_id', 'starts_at']);
    });

    // ───────────────────────────── v1.1 · Client portal & payments ─────────────────────────────
    $s->create('portal_sessions', function (T $t) {
        $t->id();
        $t->foreignId('contact_id');
        $t->foreignId('company_id');
        $t->string('token_hash', 64);
        $t->string('ua_hash', 64);
        $t->string('ip', 45)->nullable();
        $t->datetime('created_at');
        $t->datetime('last_seen_at');
        $t->datetime('revoked_at');
        $t->unique(['token_hash']);
        $t->index(['contact_id']);
    });

    $s->create('portal_tokens', function (T $t) {
        $t->id();
        $t->foreignId('contact_id');
        $t->string('token_hash', 64);
        $t->string('purpose', 20)->default('invite');
        $t->datetime('expires_at');
        $t->datetime('used_at');
        $t->datetime('created_at');
        $t->unique(['token_hash']);
    });

    $s->create('payment_attempts', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('invoice_id');
        $t->string('gateway', 20);
        $t->string('gateway_order_id', 120)->nullable();
        $t->decimal('amount')->default(0);
        $t->string('currency', 3)->default('INR');
        $t->string('status', 20)->default('created');
        $t->string('gateway_payment_id', 120)->nullable();
        $t->foreignId('payment_id');
        $t->string('source', 20)->default('public');
        $t->text('error');
        $t->timestamps();
        $t->index(['gateway', 'gateway_order_id']);
        $t->index(['invoice_id']);
    });

    $s->create('gateway_events', function (T $t) {
        $t->id();
        $t->string('gateway', 20);
        $t->string('event_id', 150);
        $t->string('type', 80)->nullable();
        $t->datetime('received_at');
        $t->unique(['gateway', 'event_id']);
    });

    // ───────────────────────────── v1.1 · Inbox, WhatsApp, signatures ─────────────────────────────
    $s->create('mailboxes', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 100);
        $t->string('host', 190);
        $t->int('port')->default(993);
        $t->string('encryption', 10)->default('ssl');
        $t->string('username', 190);
        $t->text('password');
        $t->string('folder', 100)->default('INBOX');
        $t->string('purpose', 20)->default('tickets');
        $t->bool('is_active')->default(1);
        $t->datetime('last_checked_at');
        $t->text('last_error');
        $t->timestamps();
        $t->index(['company_id']);
    });

    $s->create('inbound_messages', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('mailbox_id');
        $t->string('message_hash', 64);
        $t->string('from_email', 190)->nullable();
        $t->string('subject', 255)->nullable();
        $t->string('entity', 64)->nullable();
        $t->foreignId('record_id');
        $t->datetime('received_at');
        $t->unique(['mailbox_id', 'message_hash']);
    });

    $s->create('whatsapp_messages', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('provider', 20);
        $t->string('to_number', 30);
        $t->text('body');
        $t->string('template', 100)->nullable();
        $t->string('status', 20)->default('queued');
        $t->string('provider_message_id', 150)->nullable();
        $t->text('error');
        $t->string('entity', 64)->nullable();
        $t->foreignId('record_id');
        $t->foreignId('created_by');
        $t->datetime('created_at');
        $t->index(['company_id', 'created_at']);
    });

    $s->create('signatures', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('entity', 64);
        $t->foreignId('record_id');
        $t->string('signer_name', 150);
        $t->string('signer_email', 190)->nullable();
        $t->string('signer_ip', 45)->nullable();
        $t->string('user_agent', 255)->nullable();
        $t->longText('image');
        $t->string('document_hash', 64);
        $t->datetime('signed_at');
        $t->index(['entity', 'record_id']);
    });

    // ───────────────────────────── v1.2 · Sequences, reports, approvals ─────────────────────────────
    $s->create('email_sequences', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 150);
        $t->string('entity', 30)->default('leads');
        $t->bool('is_active')->default(1);
        $t->bool('stop_on_reply')->default(1);
        $t->foreignId('created_by');
        $t->timestamps();
        $t->index(['company_id']);
    });

    $s->create('sequence_steps', function (T $t) {
        $t->id();
        $t->foreignId('sequence_id');
        $t->int('sort')->default(0);
        $t->int('delay_days')->default(0);
        $t->string('subject', 255);
        $t->longText('body');
        $t->index(['sequence_id']);
    });

    $s->create('sequence_enrollments', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('sequence_id');
        $t->string('entity', 30);
        $t->foreignId('record_id');
        $t->string('email', 190);
        $t->string('status', 20)->default('active');
        $t->int('current_step')->default(0);
        $t->datetime('next_run_at');
        $t->datetime('last_sent_at');
        $t->foreignId('enrolled_by');
        $t->timestamps();
        $t->unique(['sequence_id', 'entity', 'record_id']);
        $t->index(['status', 'next_run_at']);
    });

    $s->create('email_unsubscribes', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('email', 190);
        $t->datetime('created_at');
        $t->unique(['company_id', 'email']);
    });

    $s->create('custom_reports', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 150);
        $t->string('entity', 64);
        $t->json('config');
        $t->bool('is_shared');
        $t->string('schedule', 10)->default('none');
        $t->text('recipients');
        $t->datetime('last_sent_at');
        $t->foreignId('created_by');
        $t->timestamps();
        $t->index(['company_id']);
    });

    $s->create('approval_flows', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('name', 150);
        $t->string('entity', 30);
        $t->decimal('min_amount')->default(0);
        $t->json('steps');
        $t->bool('is_active')->default(1);
        $t->timestamps();
        $t->index(['company_id', 'entity']);
    });

    $s->create('approval_requests', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('flow_id');
        $t->string('entity', 30);
        $t->foreignId('record_id');
        $t->string('status', 20)->default('pending');
        $t->int('current_step')->default(0);
        $t->foreignId('requested_by');
        $t->datetime('decided_at');
        $t->timestamps();
        $t->index(['entity', 'record_id']);
        $t->index(['company_id', 'status']);
    });

    $s->create('approval_actions', function (T $t) {
        $t->id();
        $t->foreignId('request_id');
        $t->int('step')->default(0);
        $t->foreignId('user_id');
        $t->string('decision', 20);
        $t->text('comment');
        $t->datetime('created_at');
        $t->index(['request_id']);
    });

    // ───────────────────────────── v2.0 · Attendance, payroll, AI, modules ─────────────────────────────
    $s->create('holidays', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->date('date');
        $t->string('name', 150);
        $t->unique(['company_id', 'date']);
    });

    $s->create('attendance', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('employee_id');
        $t->date('date');
        $t->datetime('check_in');
        $t->datetime('check_out');
        $t->int('minutes')->default(0);
        $t->string('status', 20)->default('Present');
        $t->string('source', 20)->default('web');
        $t->string('ip', 45)->nullable();
        $t->text('note');
        $t->foreignId('created_by');
        $t->timestamps();
        $t->unique(['employee_id', 'date']);
        $t->index(['company_id', 'date']);
    });

    $s->create('payroll_runs', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->string('month', 7);
        $t->string('status', 20)->default('Draft');
        $t->decimal('total_gross')->default(0);
        $t->decimal('total_deductions')->default(0);
        $t->decimal('total_net')->default(0);
        $t->foreignId('created_by');
        $t->foreignId('approved_by');
        $t->datetime('approved_at');
        $t->datetime('paid_at');
        $t->timestamps();
        $t->unique(['company_id', 'month']);
    });

    $s->create('payslips', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('run_id');
        $t->foreignId('employee_id');
        $t->int('working_days')->default(0);
        $t->decimal('paid_days', 6, 2)->default(0);
        $t->decimal('lop_days', 6, 2)->default(0);
        $t->decimal('gross')->default(0);
        $t->decimal('basic')->default(0);
        $t->decimal('hra')->default(0);
        $t->decimal('special')->default(0);
        $t->decimal('pf')->default(0);
        $t->decimal('esi')->default(0);
        $t->decimal('pt')->default(0);
        $t->decimal('tds')->default(0);
        $t->decimal('other_deductions')->default(0);
        $t->decimal('net')->default(0);
        $t->json('components');
        $t->timestamps();
        $t->unique(['run_id', 'employee_id']);
    });

    $s->create('ai_usage', function (T $t) {
        $t->id();
        $t->foreignId('company_id');
        $t->foreignId('user_id');
        $t->string('feature', 40);
        $t->string('provider', 20);
        $t->string('model', 80)->nullable();
        $t->int('input_tokens')->default(0);
        $t->int('output_tokens')->default(0);
        $t->datetime('created_at');
        $t->index(['company_id', 'created_at']);
    });

    $s->create('installed_modules', function (T $t) {
        $t->id();
        $t->string('slug', 60);
        $t->string('name', 150);
        $t->string('version', 20);
        $t->string('author', 150)->nullable();
        $t->bool('enabled')->default(1);
        $t->bool('signed');
        $t->json('manifest');
        $t->timestamps();
        $t->unique(['slug']);
    });
};

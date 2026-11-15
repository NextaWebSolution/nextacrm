# NextaCRM — Database Design

> Generated from `app/Database/schema.php` by `php tools/gen-db-doc.php`. Do not edit by hand.

**53 tables · 816 columns · 129 indexes** — MySQL 8.0+/MariaDB 10.6+ (InnoDB, utf8mb4_unicode_ci). SQLite is supported for development/testing only.

## Conventions

| Convention | Rule |
|---|---|
| Primary keys | `id BIGINT UNSIGNED AUTO_INCREMENT` on every table |
| Tenancy | Every business table has `company_id`; the record engine (`RecordService::baseWhere`) always adds `company_id = ?` server-side. Frontend filters are never trusted for isolation. |
| Ownership | Assignable records carry `owner_id` and `team_id`, used for the RBAC record scopes `own` / `team` / `all`. |
| Money | `DECIMAL(15,2)` + a `currency` (ISO 4217) column and, where relevant, `exchange_rate` to the company base currency. Floats are never used for money. |
| Time | All `DATETIME` values are stored in UTC (`SET time_zone = '+00:00'` on connect) and converted to the user's timezone on display. `DATE` columns are calendar dates. |
| Soft delete | Business records have `deleted_at`. Deleted records disappear from the app immediately and are permanently purged by the scheduler (with their line items, timeline and files) after the retention period in Settings → Security (default 90 days). Until then they can be recovered from the database or a backup. |
| Custom fields | Stored in the per-record `custom` JSON column; definitions live in `custom_fields`. No ALTER TABLE at runtime, so cPanel users never need schema privileges after install. |
| Audit columns | `created_by`, `updated_by`, `created_at`, `updated_at`. Field-level change history goes to `audit_logs`. |
| Secrets | API secrets, SMTP/storage credentials and TOTP secrets are encrypted (libsodium secretbox, key in `config/config.php`). API tokens and password-reset tokens are stored only as SHA-256 hashes; passwords as Argon2id. |
| Foreign keys | Referential integrity is enforced in the service layer (with dependency checks before delete) rather than DB constraints, so soft delete, restore and per-tenant import work consistently on shared hosts. All FK columns are indexed. |
| Migrations | Versioned PHP files in `app/Database/migrations`, tracked in the `migrations` table; the updater runs pending ones after a backup. |

## Relationship overview

```
companies 1─* users ─* company_user *─1 companies        (multi-company membership)
roles 1─* users      teams *─* users (team_user)
leads ──convert──▶ customers 1─* contacts
customers 1─* deals 1─* quotes ──convert──▶ invoices 1─* payments
quotes / invoices 1─* line_items (parent_type + parent_id) ◀── products
pipelines 1─* pipeline_stages ◀── deals
customers 1─* projects 1─* milestones 1─* tasks 1─* time_entries ──bill──▶ invoices
projects *─* users (project_members)
customers 1─* tickets 1─* ticket_replies      kb_articles (public help centre)
departments 1─* employees 1─* leave_requests
any record ─* activities / files / audit_logs / notifications (entity + record_id)
workflows ─* workflow_runs     webhooks ─* webhook_deliveries     api_tokens
```

Polymorphic tables (`activities`, `files`, `audit_logs`, `notifications`) reference a record by `entity` (e.g. `deals`) + `record_id`, indexed together.

## Tables

- **Platform:** [`companies`](#companies), [`roles`](#roles), [`users`](#users), [`teams`](#teams), [`team_user`](#team_user), [`company_user`](#company_user), [`user_sessions`](#user_sessions), [`login_attempts`](#login_attempts), [`password_resets`](#password_resets), [`api_tokens`](#api_tokens), [`settings`](#settings), [`audit_logs`](#audit_logs), [`rate_limits`](#rate_limits), [`notifications`](#notifications), [`activities`](#activities), [`folders`](#folders), [`files`](#files), [`saved_views`](#saved_views), [`custom_fields`](#custom_fields), [`workflows`](#workflows), [`workflow_runs`](#workflow_runs), [`webhooks`](#webhooks), [`webhook_deliveries`](#webhook_deliveries), [`email_queue`](#email_queue), [`lead_forms`](#lead_forms), [`currencies`](#currencies), [`taxes`](#taxes), [`pipelines`](#pipelines), [`pipeline_stages`](#pipeline_stages), [`migrations`](#migrations)
- **CRM & Sales:** [`leads`](#leads), [`customers`](#customers), [`contacts`](#contacts), [`deals`](#deals), [`products`](#products), [`quotes`](#quotes), [`invoices`](#invoices), [`line_items`](#line_items), [`payments`](#payments), [`expenses`](#expenses), [`contracts`](#contracts)
- **Delivery:** [`projects`](#projects), [`project_members`](#project_members), [`milestones`](#milestones), [`tasks`](#tasks), [`time_entries`](#time_entries)
- **Support:** [`tickets`](#tickets), [`ticket_replies`](#ticket_replies), [`kb_articles`](#kb_articles)
- **Team / HR:** [`departments`](#departments), [`employees`](#employees), [`leave_requests`](#leave_requests)
- **Calendar:** [`events`](#events)

## Platform

### companies

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(191) | no |  |
| `legal_name` | VARCHAR(191) | yes |  |
| `email` | VARCHAR(191) | yes |  |
| `phone` | VARCHAR(50) | yes |  |
| `website` | VARCHAR(191) | yes |  |
| `address` | TEXT | yes |  |
| `city` | VARCHAR(100) | yes |  |
| `state` | VARCHAR(100) | yes |  |
| `country` | VARCHAR(100) | yes |  |
| `postal_code` | VARCHAR(20) | yes |  |
| `tax_id` | VARCHAR(50) | yes |  |
| `currency` | VARCHAR(3) | no | `INR` |
| `timezone` | VARCHAR(64) | no | `Asia/Kolkata` |
| `logo_file_id` | BIGINT UNSIGNED | yes |  |
| `brand_color` | VARCHAR(9) | no | `#4f46e5` |
| `invoice_prefix` | VARCHAR(20) | no | `INV-` |
| `invoice_next` | INT | no | `1` |
| `quote_prefix` | VARCHAR(20) | no | `QT-` |
| `quote_next` | INT | no | `1` |
| `ticket_prefix` | VARCHAR(20) | no | `TKT-` |
| `ticket_next` | INT | no | `1` |
| `payment_terms_days` | INT | no | `15` |
| `invoice_terms` | TEXT | yes |  |
| `invoice_footer` | TEXT | yes |  |
| `bank_details` | TEXT | yes |  |
| `is_active` | TINYINT(1) | no | `1` |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |

### roles

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(100) | no |  |
| `description` | VARCHAR(191) | yes |  |
| `is_admin` | TINYINT(1) | no | `0` |
| `is_system` | TINYINT(1) | no | `0` |
| `permissions` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |

### users

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(150) | no |  |
| `email` | VARCHAR(191) | no |  |
| `password` | VARCHAR(191) | yes |  |
| `role_id` | BIGINT UNSIGNED | yes |  |
| `status` | VARCHAR(20) | no | `active` |
| `phone` | VARCHAR(50) | yes |  |
| `job_title` | VARCHAR(150) | yes |  |
| `avatar_file_id` | BIGINT UNSIGNED | yes |  |
| `timezone` | VARCHAR(64) | no | `Asia/Kolkata` |
| `locale` | VARCHAR(10) | no | `en` |
| `default_company_id` | BIGINT UNSIGNED | yes |  |
| `preferences` | LONGTEXT | yes |  |
| `two_factor_enabled` | TINYINT(1) | no | `0` |
| `two_factor_secret` | TEXT | yes |  |
| `two_factor_last_step` | BIGINT | yes |  |
| `recovery_codes` | TEXT | yes |  |
| `email_verified_at` | DATETIME | yes |  |
| `last_login_at` | DATETIME | yes |  |
| `last_login_ip` | VARCHAR(45) | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (email) · (role_id)

### teams

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(150) | no |  |
| `description` | VARCHAR(191) | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |

### team_user

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `team_id` | BIGINT UNSIGNED | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |

**Indexes:** UNIQUE (team_id, user_id) · (user_id)

### company_user

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |

**Indexes:** UNIQUE (company_id, user_id) · (user_id)

### user_sessions

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `token_hash` | VARCHAR(64) | no |  |
| `ip` | VARCHAR(45) | yes |  |
| `user_agent` | VARCHAR(191) | yes |  |
| `ua_hash` | VARCHAR(64) | yes |  |
| `created_at` | DATETIME | yes |  |
| `last_seen_at` | DATETIME | yes |  |
| `revoked_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (token_hash) · (user_id, revoked_at)

### login_attempts

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `email` | VARCHAR(191) | no |  |
| `ip` | VARCHAR(45) | no |  |
| `success` | TINYINT(1) | no | `0` |
| `created_at` | DATETIME | yes |  |

**Indexes:** (email, created_at) · (ip, created_at)

### password_resets

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `token_hash` | VARCHAR(64) | no |  |
| `purpose` | VARCHAR(20) | no | `reset` |
| `expires_at` | DATETIME | yes |  |
| `used_at` | DATETIME | yes |  |
| `created_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (token_hash)

### api_tokens

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(100) | no |  |
| `token_hash` | VARCHAR(64) | no |  |
| `token_prefix` | VARCHAR(12) | no |  |
| `scope` | VARCHAR(10) | no | `read` |
| `expires_at` | DATETIME | yes |  |
| `last_used_at` | DATETIME | yes |  |
| `last_used_ip` | VARCHAR(45) | yes |  |
| `revoked_at` | DATETIME | yes |  |
| `created_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (token_hash)

### settings

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT | no | `0` |
| `k` | VARCHAR(150) | no |  |
| `v` | LONGTEXT | yes |  |
| `updated_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (company_id, k)

### audit_logs

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `action` | VARCHAR(64) | no |  |
| `entity` | VARCHAR(64) | yes |  |
| `record_id` | BIGINT | yes |  |
| `changes` | LONGTEXT | yes |  |
| `ip` | VARCHAR(45) | yes |  |
| `user_agent` | VARCHAR(191) | yes |  |
| `created_at` | DATETIME | yes |  |

**Indexes:** (entity, record_id) · (company_id, created_at) · (user_id)

### rate_limits

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `k` | VARCHAR(64) | no |  |
| `hits` | INT | no | `0` |
| `reset_at` | BIGINT | no | `0` |

**Indexes:** UNIQUE (k)

### notifications

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `type` | VARCHAR(50) | no |  |
| `title` | VARCHAR(191) | no |  |
| `body` | TEXT | yes |  |
| `url` | VARCHAR(500) | yes |  |
| `entity` | VARCHAR(64) | yes |  |
| `record_id` | BIGINT | yes |  |
| `read_at` | DATETIME | yes |  |
| `created_at` | DATETIME | yes |  |

**Indexes:** (user_id, read_at)

### activities

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `entity` | VARCHAR(64) | no |  |
| `record_id` | BIGINT | no |  |
| `type` | VARCHAR(30) | no | `note` |
| `subject` | VARCHAR(191) | yes |  |
| `body` | TEXT | yes |  |
| `meta` | LONGTEXT | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `occurred_at` | DATETIME | yes |  |
| `created_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (entity, record_id) · (company_id, created_at)

### folders

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `parent_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(150) | no |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `created_at` | DATETIME | yes |  |

**Indexes:** (company_id, parent_id)

### files

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `folder_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(191) | no |  |
| `path` | VARCHAR(500) | no |  |
| `driver` | VARCHAR(20) | no |  |
| `mime` | VARCHAR(150) | yes |  |
| `ext` | VARCHAR(20) | yes |  |
| `size` | BIGINT | no | `0` |
| `checksum` | VARCHAR(64) | yes |  |
| `entity` | VARCHAR(64) | yes |  |
| `record_id` | BIGINT | yes |  |
| `description` | VARCHAR(191) | yes |  |
| `is_public` | TINYINT(1) | no | `0` |
| `uploaded_by` | BIGINT UNSIGNED | yes |  |
| `created_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (entity, record_id) · (company_id, folder_id)

### saved_views

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `entity` | VARCHAR(64) | no |  |
| `name` | VARCHAR(100) | no |  |
| `config` | LONGTEXT | yes |  |
| `is_shared` | TINYINT(1) | no | `0` |
| `sort` | INT | no | `0` |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |

**Indexes:** (company_id, entity)

### custom_fields

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `entity` | VARCHAR(64) | no |  |
| `name` | VARCHAR(64) | no |  |
| `label` | VARCHAR(100) | no |  |
| `type` | VARCHAR(20) | no |  |
| `options` | LONGTEXT | yes |  |
| `required` | TINYINT(1) | no | `0` |
| `show_in_list` | TINYINT(1) | no | `0` |
| `sort` | INT | no | `0` |
| `created_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (company_id, entity, name)

### workflows

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(150) | no |  |
| `entity` | VARCHAR(64) | no |  |
| `trigger_type` | VARCHAR(30) | no |  |
| `trigger_config` | LONGTEXT | yes |  |
| `conditions` | LONGTEXT | yes |  |
| `actions` | LONGTEXT | yes |  |
| `is_active` | TINYINT(1) | no | `1` |
| `run_count` | INT | no | `0` |
| `last_run_at` | DATETIME | yes |  |
| `webhook_token` | VARCHAR(64) | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |

**Indexes:** (company_id, entity, trigger_type)

### workflow_runs

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `workflow_id` | BIGINT UNSIGNED | yes |  |
| `entity` | VARCHAR(64) | yes |  |
| `record_id` | BIGINT | yes |  |
| `status` | VARCHAR(20) | no |  |
| `log` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |

**Indexes:** (workflow_id, created_at) · (workflow_id, record_id)

### webhooks

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(150) | no |  |
| `url` | VARCHAR(500) | no |  |
| `secret` | TEXT | yes |  |
| `events` | LONGTEXT | yes |  |
| `is_active` | TINYINT(1) | no | `1` |
| `failure_count` | INT | no | `0` |
| `last_status` | INT | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |

### webhook_deliveries

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `webhook_id` | BIGINT UNSIGNED | yes |  |
| `event` | VARCHAR(64) | no |  |
| `payload` | LONGTEXT | yes |  |
| `status` | VARCHAR(20) | no | `pending` |
| `attempts` | INT | no | `0` |
| `response_code` | INT | yes |  |
| `response_body` | TEXT | yes |  |
| `next_attempt_at` | DATETIME | yes |  |
| `delivered_at` | DATETIME | yes |  |
| `created_at` | DATETIME | yes |  |

**Indexes:** (status, next_attempt_at) · (webhook_id, created_at)

### email_queue

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `to_email` | VARCHAR(191) | no |  |
| `subject` | VARCHAR(255) | no |  |
| `body` | LONGTEXT | yes |  |
| `attachments` | LONGTEXT | yes |  |
| `reply_to` | VARCHAR(191) | yes |  |
| `status` | VARCHAR(20) | no | `pending` |
| `attempts` | INT | no | `0` |
| `error` | VARCHAR(500) | yes |  |
| `created_at` | DATETIME | yes |  |
| `sent_at` | DATETIME | yes |  |

**Indexes:** (status)

### lead_forms

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(150) | no |  |
| `token` | VARCHAR(64) | no |  |
| `fields` | LONGTEXT | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `default_status` | VARCHAR(50) | yes |  |
| `default_source` | VARCHAR(50) | yes |  |
| `success_message` | VARCHAR(500) | yes |  |
| `redirect_url` | VARCHAR(500) | yes |  |
| `is_active` | TINYINT(1) | no | `1` |
| `submissions` | INT | no | `0` |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (token)

### currencies

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `code` | VARCHAR(3) | no |  |
| `name` | VARCHAR(60) | no |  |
| `rate` | DECIMAL(18,6) | no | `1` |
| `is_active` | TINYINT(1) | no | `1` |
| `updated_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (code)

### taxes

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(60) | no |  |
| `rate` | DECIMAL(7,3) | no | `0` |
| `is_default` | TINYINT(1) | no | `0` |
| `created_at` | DATETIME | yes |  |

**Indexes:** (company_id)

### pipelines

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(100) | no |  |
| `is_default` | TINYINT(1) | no | `0` |
| `sort` | INT | no | `0` |
| `created_at` | DATETIME | yes |  |

### pipeline_stages

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `pipeline_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(100) | no |  |
| `probability` | INT | no | `0` |
| `color` | VARCHAR(20) | no | `slate` |
| `sort` | INT | no | `0` |
| `is_won` | TINYINT(1) | no | `0` |
| `is_lost` | TINYINT(1) | no | `0` |

**Indexes:** (pipeline_id, sort)

### migrations

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(150) | no |  |
| `ran_at` | DATETIME | yes |  |

**Indexes:** UNIQUE (name)

## CRM & Sales

### leads

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(150) | no |  |
| `company_name` | VARCHAR(191) | yes |  |
| `email` | VARCHAR(191) | yes |  |
| `phone` | VARCHAR(50) | yes |  |
| `whatsapp` | VARCHAR(50) | yes |  |
| `job_title` | VARCHAR(150) | yes |  |
| `website` | VARCHAR(191) | yes |  |
| `source` | VARCHAR(50) | yes |  |
| `status` | VARCHAR(50) | no | `New` |
| `rating` | VARCHAR(20) | yes |  |
| `qualification` | VARCHAR(20) | yes |  |
| `estimated_value` | DECIMAL(15,2) | yes |  |
| `currency` | VARCHAR(3) | yes |  |
| `industry` | VARCHAR(100) | yes |  |
| `company_size` | VARCHAR(30) | yes |  |
| `annual_revenue` | DECIMAL(15,2) | yes |  |
| `address` | TEXT | yes |  |
| `city` | VARCHAR(100) | yes |  |
| `state` | VARCHAR(100) | yes |  |
| `country` | VARCHAR(100) | yes |  |
| `follow_up_date` | DATE | yes |  |
| `score` | INT | no | `0` |
| `tags` | VARCHAR(500) | yes |  |
| `description` | TEXT | yes |  |
| `utm_source` | VARCHAR(100) | yes |  |
| `utm_medium` | VARCHAR(100) | yes |  |
| `utm_campaign` | VARCHAR(150) | yes |  |
| `lost_reason` | VARCHAR(100) | yes |  |
| `converted_at` | DATETIME | yes |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `contact_id` | BIGINT UNSIGNED | yes |  |
| `deal_id` | BIGINT UNSIGNED | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, status) · (email) · (phone)

### customers

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(191) | no |  |
| `type` | VARCHAR(30) | no | `Customer` |
| `email` | VARCHAR(191) | yes |  |
| `phone` | VARCHAR(50) | yes |  |
| `website` | VARCHAR(191) | yes |  |
| `industry` | VARCHAR(100) | yes |  |
| `tax_id` | VARCHAR(50) | yes |  |
| `currency` | VARCHAR(3) | yes |  |
| `billing_address` | TEXT | yes |  |
| `city` | VARCHAR(100) | yes |  |
| `state` | VARCHAR(100) | yes |  |
| `country` | VARCHAR(100) | yes |  |
| `postal_code` | VARCHAR(20) | yes |  |
| `company_size` | VARCHAR(30) | yes |  |
| `annual_revenue` | DECIMAL(15,2) | yes |  |
| `source` | VARCHAR(50) | yes |  |
| `tags` | VARCHAR(500) | yes |  |
| `description` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, name)

### contacts

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `first_name` | VARCHAR(100) | no |  |
| `last_name` | VARCHAR(100) | yes |  |
| `name` | VARCHAR(200) | no |  |
| `email` | VARCHAR(191) | yes |  |
| `phone` | VARCHAR(50) | yes |  |
| `mobile` | VARCHAR(50) | yes |  |
| `job_title` | VARCHAR(150) | yes |  |
| `department` | VARCHAR(100) | yes |  |
| `is_primary` | TINYINT(1) | no | `0` |
| `birthday` | DATE | yes |  |
| `linkedin` | VARCHAR(191) | yes |  |
| `address` | TEXT | yes |  |
| `city` | VARCHAR(100) | yes |  |
| `country` | VARCHAR(100) | yes |  |
| `tags` | VARCHAR(500) | yes |  |
| `next_follow_up` | DATE | yes |  |
| `description` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (customer_id) · (email)

### deals

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(191) | no |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `contact_id` | BIGINT UNSIGNED | yes |  |
| `lead_id` | BIGINT UNSIGNED | yes |  |
| `pipeline_id` | BIGINT UNSIGNED | yes |  |
| `stage_id` | BIGINT UNSIGNED | yes |  |
| `amount` | DECIMAL(15,2) | no | `0` |
| `currency` | VARCHAR(3) | yes |  |
| `probability` | INT | no | `0` |
| `expected_close` | DATE | yes |  |
| `closed_at` | DATETIME | yes |  |
| `status` | VARCHAR(10) | no | `open` |
| `type` | VARCHAR(30) | yes |  |
| `is_recurring` | TINYINT(1) | no | `0` |
| `recurring_interval` | VARCHAR(20) | yes |  |
| `lost_reason` | VARCHAR(100) | yes |  |
| `competitor` | VARCHAR(150) | yes |  |
| `source` | VARCHAR(50) | yes |  |
| `next_step` | VARCHAR(191) | yes |  |
| `tags` | VARCHAR(500) | yes |  |
| `description` | TEXT | yes |  |
| `stage_changed_at` | DATETIME | yes |  |
| `sort` | INT | no | `0` |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, pipeline_id, stage_id) · (customer_id)

### products

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(191) | no |  |
| `sku` | VARCHAR(64) | yes |  |
| `type` | VARCHAR(20) | no | `Service` |
| `unit` | VARCHAR(20) | no | `unit` |
| `price` | DECIMAL(15,2) | no | `0` |
| `cost` | DECIMAL(15,2) | yes |  |
| `tax_id` | BIGINT UNSIGNED | yes |  |
| `currency` | VARCHAR(3) | yes |  |
| `hsn_sac` | VARCHAR(20) | yes |  |
| `description` | TEXT | yes |  |
| `is_active` | TINYINT(1) | no | `1` |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (created_at)

### quotes

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `number` | VARCHAR(50) | no |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `contact_id` | BIGINT UNSIGNED | yes |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `subject` | VARCHAR(191) | yes |  |
| `status` | VARCHAR(30) | no | `Draft` |
| `issue_date` | DATE | yes |  |
| `currency` | VARCHAR(3) | no | `INR` |
| `exchange_rate` | DECIMAL(18,6) | no | `1` |
| `subtotal` | DECIMAL(15,2) | no | `0` |
| `discount_type` | VARCHAR(10) | no | `percent` |
| `discount_value` | DECIMAL(15,2) | no | `0` |
| `discount_total` | DECIMAL(15,2) | no | `0` |
| `tax_total` | DECIMAL(15,2) | no | `0` |
| `adjustment` | DECIMAL(15,2) | no | `0` |
| `total` | DECIMAL(15,2) | no | `0` |
| `notes` | TEXT | yes |  |
| `terms` | TEXT | yes |  |
| `public_token` | VARCHAR(64) | yes |  |
| `sent_at` | DATETIME | yes |  |
| `deal_id` | BIGINT UNSIGNED | yes |  |
| `valid_until` | DATE | yes |  |
| `accepted_at` | DATETIME | yes |  |
| `accepted_by_name` | VARCHAR(150) | yes |  |
| `invoice_id` | BIGINT UNSIGNED | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, status) · (customer_id) · (public_token)

### invoices

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `number` | VARCHAR(50) | no |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `contact_id` | BIGINT UNSIGNED | yes |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `subject` | VARCHAR(191) | yes |  |
| `status` | VARCHAR(30) | no | `Draft` |
| `issue_date` | DATE | yes |  |
| `currency` | VARCHAR(3) | no | `INR` |
| `exchange_rate` | DECIMAL(18,6) | no | `1` |
| `subtotal` | DECIMAL(15,2) | no | `0` |
| `discount_type` | VARCHAR(10) | no | `percent` |
| `discount_value` | DECIMAL(15,2) | no | `0` |
| `discount_total` | DECIMAL(15,2) | no | `0` |
| `tax_total` | DECIMAL(15,2) | no | `0` |
| `adjustment` | DECIMAL(15,2) | no | `0` |
| `total` | DECIMAL(15,2) | no | `0` |
| `notes` | TEXT | yes |  |
| `terms` | TEXT | yes |  |
| `public_token` | VARCHAR(64) | yes |  |
| `sent_at` | DATETIME | yes |  |
| `quote_id` | BIGINT UNSIGNED | yes |  |
| `due_date` | DATE | yes |  |
| `amount_paid` | DECIMAL(15,2) | no | `0` |
| `balance` | DECIMAL(15,2) | no | `0` |
| `paid_at` | DATETIME | yes |  |
| `is_recurring` | TINYINT(1) | no | `0` |
| `recurring_interval` | VARCHAR(20) | yes |  |
| `recurring_next_date` | DATE | yes |  |
| `recurring_end_date` | DATE | yes |  |
| `recurring_parent_id` | BIGINT UNSIGNED | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, status) · (customer_id) · (due_date) · (public_token)

### line_items

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `parent_type` | VARCHAR(20) | no |  |
| `parent_id` | BIGINT UNSIGNED | yes |  |
| `product_id` | BIGINT UNSIGNED | yes |  |
| `description` | TEXT | yes |  |
| `quantity` | DECIMAL(12,3) | no | `1` |
| `unit` | VARCHAR(20) | yes |  |
| `unit_price` | DECIMAL(15,2) | no | `0` |
| `discount_percent` | DECIMAL(7,3) | no | `0` |
| `tax_id` | BIGINT UNSIGNED | yes |  |
| `tax_rate` | DECIMAL(7,3) | no | `0` |
| `tax_amount` | DECIMAL(15,2) | no | `0` |
| `line_total` | DECIMAL(15,2) | no | `0` |
| `time_entry_ids` | TEXT | yes |  |
| `sort` | INT | no | `0` |

**Indexes:** (parent_type, parent_id)

### payments

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `invoice_id` | BIGINT UNSIGNED | yes |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `amount` | DECIMAL(15,2) | no | `0` |
| `currency` | VARCHAR(3) | yes |  |
| `paid_on` | DATE | yes |  |
| `method` | VARCHAR(50) | yes |  |
| `reference` | VARCHAR(150) | yes |  |
| `notes` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (created_at) · (invoice_id)

### expenses

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `title` | VARCHAR(191) | no |  |
| `category` | VARCHAR(100) | yes |  |
| `amount` | DECIMAL(15,2) | no | `0` |
| `tax_amount` | DECIMAL(15,2) | no | `0` |
| `currency` | VARCHAR(3) | yes |  |
| `expense_date` | DATE | yes |  |
| `vendor` | VARCHAR(150) | yes |  |
| `payment_method` | VARCHAR(50) | yes |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `is_billable` | TINYINT(1) | no | `0` |
| `status` | VARCHAR(20) | no | `Pending` |
| `approved_by` | BIGINT UNSIGNED | yes |  |
| `approved_at` | DATETIME | yes |  |
| `invoice_id` | BIGINT UNSIGNED | yes |  |
| `notes` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, expense_date)

### contracts

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `title` | VARCHAR(191) | no |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `contact_id` | BIGINT UNSIGNED | yes |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `type` | VARCHAR(50) | yes |  |
| `value` | DECIMAL(15,2) | yes |  |
| `currency` | VARCHAR(3) | yes |  |
| `start_date` | DATE | yes |  |
| `end_date` | DATE | yes |  |
| `renewal_reminder_days` | INT | no | `30` |
| `status` | VARCHAR(20) | no | `Draft` |
| `description` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (end_date)

## Delivery

### projects

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(191) | no |  |
| `code` | VARCHAR(30) | yes |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `deal_id` | BIGINT UNSIGNED | yes |  |
| `status` | VARCHAR(30) | no | `Not Started` |
| `priority` | VARCHAR(20) | no | `Medium` |
| `billing_type` | VARCHAR(20) | no | `Fixed` |
| `budget` | DECIMAL(15,2) | yes |  |
| `hourly_rate` | DECIMAL(15,2) | yes |  |
| `currency` | VARCHAR(3) | yes |  |
| `start_date` | DATE | yes |  |
| `due_date` | DATE | yes |  |
| `completed_at` | DATETIME | yes |  |
| `progress` | INT | no | `0` |
| `color` | VARCHAR(20) | yes |  |
| `tags` | VARCHAR(500) | yes |  |
| `description` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, status) · (customer_id)

### project_members

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `role` | VARCHAR(50) | yes |  |

**Indexes:** UNIQUE (project_id, user_id) · (user_id)

### milestones

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `name` | VARCHAR(191) | no |  |
| `due_date` | DATE | yes |  |
| `status` | VARCHAR(20) | no | `Open` |
| `amount` | DECIMAL(15,2) | yes |  |
| `description` | TEXT | yes |  |
| `sort` | INT | no | `0` |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (created_at) · (project_id)

### tasks

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `title` | VARCHAR(191) | no |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `milestone_id` | BIGINT UNSIGNED | yes |  |
| `parent_id` | BIGINT UNSIGNED | yes |  |
| `related_entity` | VARCHAR(64) | yes |  |
| `related_id` | BIGINT | yes |  |
| `status` | VARCHAR(30) | no | `To Do` |
| `priority` | VARCHAR(20) | no | `Medium` |
| `start_date` | DATE | yes |  |
| `due_date` | DATE | yes |  |
| `estimate_hours` | DECIMAL(8,2) | yes |  |
| `spent_hours` | DECIMAL(8,2) | no | `0` |
| `progress` | INT | no | `0` |
| `is_billable` | TINYINT(1) | no | `0` |
| `depends_on` | VARCHAR(255) | yes |  |
| `completed_at` | DATETIME | yes |  |
| `tags` | VARCHAR(500) | yes |  |
| `description` | TEXT | yes |  |
| `sort` | INT | no | `0` |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, status) · (project_id) · (related_entity, related_id) · (due_date)

### time_entries

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `task_id` | BIGINT UNSIGNED | yes |  |
| `entry_date` | DATE | yes |  |
| `started_at` | DATETIME | yes |  |
| `ended_at` | DATETIME | yes |  |
| `minutes` | INT | no | `0` |
| `is_running` | TINYINT(1) | no | `0` |
| `is_billable` | TINYINT(1) | no | `1` |
| `hourly_rate` | DECIMAL(15,2) | yes |  |
| `is_invoiced` | TINYINT(1) | no | `0` |
| `invoice_id` | BIGINT UNSIGNED | yes |  |
| `description` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (project_id) · (task_id) · (assigned_to, is_running)

## Support

### tickets

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `number` | VARCHAR(50) | no |  |
| `subject` | VARCHAR(191) | no |  |
| `customer_id` | BIGINT UNSIGNED | yes |  |
| `contact_id` | BIGINT UNSIGNED | yes |  |
| `project_id` | BIGINT UNSIGNED | yes |  |
| `priority` | VARCHAR(20) | no | `Medium` |
| `type` | VARCHAR(50) | yes |  |
| `status` | VARCHAR(30) | no | `Open` |
| `channel` | VARCHAR(20) | no | `Web` |
| `requester_name` | VARCHAR(150) | yes |  |
| `requester_email` | VARCHAR(191) | yes |  |
| `description` | TEXT | yes |  |
| `first_response_due` | DATETIME | yes |  |
| `resolution_due` | DATETIME | yes |  |
| `first_responded_at` | DATETIME | yes |  |
| `resolved_at` | DATETIME | yes |  |
| `sla_status` | VARCHAR(20) | yes |  |
| `satisfaction` | INT | yes |  |
| `tags` | VARCHAR(500) | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, status) · (customer_id)

### ticket_replies

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `ticket_id` | BIGINT UNSIGNED | yes |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `body` | TEXT | yes |  |
| `is_internal` | TINYINT(1) | no | `0` |
| `author_name` | VARCHAR(150) | yes |  |
| `author_email` | VARCHAR(191) | yes |  |
| `created_at` | DATETIME | yes |  |

**Indexes:** (ticket_id)

### kb_articles

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `title` | VARCHAR(191) | no |  |
| `slug` | VARCHAR(191) | no |  |
| `category` | VARCHAR(100) | yes |  |
| `body` | LONGTEXT | yes |  |
| `status` | VARCHAR(20) | no | `Draft` |
| `views` | INT | no | `0` |
| `tags` | VARCHAR(500) | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (created_at)

## Team / HR

### departments

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `name` | VARCHAR(150) | no |  |
| `head_user_id` | BIGINT UNSIGNED | yes |  |
| `description` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (created_at)

### employees

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `user_id` | BIGINT UNSIGNED | yes |  |
| `employee_code` | VARCHAR(30) | yes |  |
| `first_name` | VARCHAR(100) | no |  |
| `last_name` | VARCHAR(100) | yes |  |
| `name` | VARCHAR(200) | no |  |
| `email` | VARCHAR(191) | yes |  |
| `phone` | VARCHAR(50) | yes |  |
| `department_id` | BIGINT UNSIGNED | yes |  |
| `designation` | VARCHAR(150) | yes |  |
| `manager_id` | BIGINT UNSIGNED | yes |  |
| `employment_type` | VARCHAR(30) | no | `Full-time` |
| `status` | VARCHAR(30) | no | `Active` |
| `joining_date` | DATE | yes |  |
| `exit_date` | DATE | yes |  |
| `date_of_birth` | DATE | yes |  |
| `salary` | DECIMAL(15,2) | yes |  |
| `salary_currency` | VARCHAR(3) | yes |  |
| `leave_balance` | DECIMAL(6,1) | no | `0` |
| `address` | TEXT | yes |  |
| `emergency_contact` | VARCHAR(191) | yes |  |
| `skills` | VARCHAR(500) | yes |  |
| `notes` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (department_id)

### leave_requests

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `employee_id` | BIGINT UNSIGNED | yes |  |
| `type` | VARCHAR(50) | no |  |
| `start_date` | DATE | yes |  |
| `end_date` | DATE | yes |  |
| `days` | DECIMAL(5,1) | no | `1` |
| `reason` | TEXT | yes |  |
| `status` | VARCHAR(20) | no | `Pending` |
| `approved_by` | BIGINT UNSIGNED | yes |  |
| `approved_at` | DATETIME | yes |  |
| `review_note` | VARCHAR(500) | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (employee_id) · (start_date)

## Calendar

### events

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | no |  |
| `title` | VARCHAR(191) | no |  |
| `type` | VARCHAR(30) | no | `Meeting` |
| `starts_at` | DATETIME | yes |  |
| `ends_at` | DATETIME | yes |  |
| `all_day` | TINYINT(1) | no | `0` |
| `location` | VARCHAR(191) | yes |  |
| `meeting_url` | VARCHAR(500) | yes |  |
| `related_entity` | VARCHAR(64) | yes |  |
| `related_id` | BIGINT | yes |  |
| `attendees` | VARCHAR(500) | yes |  |
| `reminder_minutes` | INT | yes |  |
| `reminder_sent` | TINYINT(1) | no | `0` |
| `description` | TEXT | yes |  |
| `company_id` | BIGINT UNSIGNED | yes |  |
| `assigned_to` | BIGINT UNSIGNED | yes |  |
| `created_by` | BIGINT UNSIGNED | yes |  |
| `updated_by` | BIGINT UNSIGNED | yes |  |
| `custom` | LONGTEXT | yes |  |
| `created_at` | DATETIME | yes |  |
| `updated_at` | DATETIME | yes |  |
| `deleted_at` | DATETIME | yes |  |

**Indexes:** (company_id, deleted_at) · (assigned_to) · (created_at) · (company_id, starts_at)


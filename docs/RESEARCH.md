# NextaCRM — Research Notes

Prepared before development. This file records what was actually inspected, what was learned, and which ideas were adopted. Anything not inspected is marked as "documented feature" (taken from public docs) rather than "verified in source".

## 1. Source code inspected

| Platform | Version inspected | How obtained | Stack |
|---|---|---|---|
| EspoCRM | 10.1.1 (master, 12 Nov 2026) | Cloned from github.com/espocrm/espocrm — **the EspoCRM zip was not among the uploads**, so the public repository was used instead | PHP 8, own ORM, Backbone-based SPA |
| SuiteCRM | 7.15.2 (hotfix branch) | Uploaded zip | PHP, SugarCRM CE 6.5 heritage, Smarty templates |
| ERPNext | 17.0.0-dev (develop) | Uploaded zip | Python, Frappe framework, MariaDB |
| Odoo Community | 20.0 | Uploaded zip (649 addons) | Python, own ORM, OWL front end, PostgreSQL |
| Twenty | main (server 0.2.x package) | Uploaded zip | TypeScript, NestJS, GraphQL, React, PostgreSQL, Redis, BullMQ |

## 2. Findings per platform (verified in source)

### EspoCRM
- **Metadata-driven entities.** Every entity is described in JSON (`application/Espo/Modules/Crm/Resources/metadata/entityDefs/Lead.json` etc.): fields, links, indexes. List/detail layouts, ACL and select rules are also metadata (`aclDefs`, `clientDefs`, `selectDefs`). This is the single biggest reason Espo stays maintainable with dozens of entities. **Adopted**: NextaCRM defines entities as PHP metadata arrays and drives list, kanban, detail, forms, search, filters, export, import, API and permissions from them.
- **ACL with levels, not booleans.** `Core/Acl/` has separate checkers for read/edit/delete/create/stream and ownership checkers (`OwnershipOwnChecker`, `OwnershipTeamChecker`, `OwnershipSharedChecker`). Levels are all / team / own / no. **Adopted** (scope levels for view/edit/delete, booleans for create/export/import/approve/assign/manage).
- **File storage abstraction.** `Core/FileStorage/Storages/` contains `EspoUploadDir.php` and `AwsS3.php`; S3 goes through League Flysystem + AsyncAws. Only two drivers. **Improved**: NextaCRM ships six drivers (local, S3, Wasabi, generic S3-compatible incl. R2/Spaces/MinIO, FTP, SFTP) with zero external SDKs for S3 (own SigV4 signer) so it works on shared hosting.
- **Password hashing**: `Core/Utils/PasswordHash.php` uses `PASSWORD_BCRYPT` with a legacy `crypt(md5())` fallback. **Improved**: Argon2id with automatic rehash.
- **Brute-force protection**: `Core/Authentication/Hook/Hooks/FailedAttemptsLimit.php`, `UsernameFailedAttemptsLimit.php`, `FailedCodeAttemptsLimit.php` count failures per IP, per username and per 2FA code within a period. **Adopted** (all three counters).
- **2FA**: `Core/Authentication/TwoFactor/` supports TOTP, Email and SMS. **Adopted** TOTP + recovery codes in v1.
- **Webhooks**: `Core/Webhook/` (Manager, Queue, Sender) with an `AddressUtil` allow-list check. **Adopted** queue + retry; **improved** with DNS-resolved private-IP blocking (Espo relies on a configured allow-list).
- **Tools worth noting**: LeadCapture (web-to-lead), GlobalSearch, Kanban, Pipeline, MassUpdate, Import/Export, Pdf, OpenApi, WorkingTime. All have NextaCRM equivalents. Espo's advanced workflows/BPM are a **paid extension** (Advanced Pack), not in this repo — confirmed: no Workflow scope in core metadata.

### SuiteCRM 7
- Classic SugarCRM module layout (`modules/Leads`, `modules/Opportunities`, …) with ~120 modules including AOS (quotes, invoices, contracts, products, PDF templates), AOW (workflows), AOR (reports), AOP (case portal), Projects with Gantt, Surveys, Events, Maps.
- **Workflow engine (AOW)**: `modules/AOW_Actions/actions.php` registers exactly four actions — CreateRecord, ModifyRecord, SendEmail, ComputeField; conditions live in `AOW_Conditions`; run-on options are "on save / scheduler / both". **Adopted concept** (event + condition + action list); **improved** with webhook, notification, assignment and task-creation actions and a run log.
- **Security observations**: `modules/Users/User.php::getPasswordHash()` hashes `md5($password)` before bcrypt — an MD5 pre-hash inherited from Sugar. Heavy server-rendered Smarty UI, mostly desktop-first. **Not adopted.**
- **Security groups** (`modules/SecurityGroups`) give record-level team sharing. **Adopted conceptually** via team scope.

### ERPNext (Frappe)
- **Lead** doctype (`erpnext/crm/doctype/lead/lead.json`) includes qualification fields (`qualification_status`, `qualified_by`, `qualified_on`), `annual_revenue`, `no_of_employees`, `whatsapp_no`, `territory`, `market_segment`. **Adopted**: qualification status, company size, revenue, WhatsApp number on leads.
- **Opportunity lost reasons** (`opportunity_lost_reason`, `competitor`). **Adopted**: lost reason + competitor on deals.
- **Task** doctype supports `depends_on`, `parent_task`, `is_milestone`, `expected_time`, `actual_time`, `progress`, costing/billing amounts. **Adopted**: parent task, dependencies, estimate vs actual, progress, billable.
- **Timesheet** links to sales invoice with billable/billed hours. **Adopted**: billable time entries that can be pulled into an invoice.
- **Issue + Service Level Agreement** (`support/doctype/service_level_agreement`): response/resolution targets per priority, pause SLA on status, `first_responded_on`, `sla_resolution_by`. **Adopted**: SLA policy per priority with first-response and resolution due dates and breach flags.
- Accounting is double-entry (journal entries, payment entries, tax templates, withholding). NextaCRM deliberately stops at invoicing/payments/expenses and exports to accounting systems — full double-entry is out of scope for a CRM.
- HR has been moved to a separate HRMS app (no `erpnext/hr` directory in this branch).

### Odoo Community 20
- Verified that **helpdesk, documents, sign, knowledge, planning, approvals, web_studio, account_accountant, marketing_automation and social are absent** from Community addons — they are Enterprise-only. So in Community there is no helpdesk, no document manager, no approval flows and no Studio customizer.
- **crm.lead** model (`addons/crm/models/crm_lead.py`) combines leads and opportunities in one table with `type`, `stage_id`, `probability` + `automated_probability`, `expected_revenue`, `prorated_revenue`, `recurring_revenue`/`recurring_plan`, `lost_reason_id`, `duplicate_lead_ids`, UTM `campaign/medium/source`. **Adopted**: weighted (prorated) pipeline value, recurring revenue (MRR) on deals, UTM source tracking, duplicate detection by email/phone.
- **base_automation** triggers (verified list): on create, on update, on create-or-update, on deletion, stage set, user set, tag set, state set, priority set, archive/unarchive, based on date field, after creation, after last update, on webhook. Server action types: write, create, copy, code, webhook, multi. **Adopted** most trigger types (create, update, field-changed-to, delete, date-based/scheduled, incoming webhook) and no "code" action (security).
- **project.task** has personal stages, dependencies (`depend_on_ids`), milestones, recurrence, allocated hours, multiple assignees (`user_ids`). **Adopted**: milestones, dependencies, estimate hours, multiple watchers.

### Twenty
- **Workflow actions** (`modules/workflow/workflow-executor/workflow-actions/`): record CRUD, send mail, HTTP request, if/else, filter, delay, iterator, form, code, AI agent. Triggers (`WorkflowTriggerType`): DATABASE_EVENT, MANUAL, CRON, WEBHOOK. **Adopted**: the same four trigger families; manual "Run workflow" button on records.
- **SSRF-safe HTTP client** (`core-modules/secure-http-client/`): resolves DNS, blocks private (`10/8`, `127/8`, `169.254/16` …) and link-local ranges *after* resolution in the socket lookup handler, redacts URLs in logs. **Adopted** in PHP: resolve → block private/reserved → pin the resolved IP with `CURLOPT_RESOLVE` → no redirects.
- **File storage drivers**: `local.driver.ts`, `s3.driver.ts`, wrapped by `validated-storage.driver.ts` and `is-safe-relative-path.validator.ts`. **Adopted** the "validated wrapper" idea: every driver receives only normalized, traversal-free keys.
- **Front end**: `object-record/` has record-table, record-board (kanban), record-calendar, record-show, record-inline-cell, record-filter-group, record-sort, record-merge, spreadsheet-import, and `views/` for saved views. Command menu (`command-menu/`) and keyboard shortcut menu. **Adopted**: inline cell editing, saved views, command palette (⌘K), keyboard shortcuts, board view.
- **Operational weight**: requires Node.js, PostgreSQL, Redis and a worker process. Not deployable on cPanel shared hosting — the gap NextaCRM targets.

## 3. Other platforms (documented features, not source-reviewed)

| Platform | What we took from public documentation |
|---|---|
| Perfex CRM | The closest commercial PHP analogue: leads, customers, projects with Gantt and timers, estimates→invoices, recurring invoices, tickets imported per department email, contracts, knowledge base, custom fields on most entities, client portal. Confirms the IT-agency module set. |
| Dolibarr | Strict "enable only the modules you need" philosophy; simple install. **Adopted**: per-module enable/disable in admin. |
| SuiteDash | One shared data layer for CRM, projects, invoicing and client portal; white-label. **Adopted**: single schema, company branding on documents. Client portal is on the v1.1 roadmap. |
| Frappe CRM | Kanban with configurable card fields, saved/pinned/public views (filters + sort + columns), PWA for mobile, WhatsApp/telephony integrations. **Adopted**: saved views (private/public), PWA manifest. |
| HubSpot / Salesforce / Zoho / Freshsales | Timeline-centric record pages, deal pipelines with weighted forecast, web forms, lead scoring. **Adopted**: activity timeline on every record, rule-based lead score. |
| Linear / ClickUp / Asana / Jira / Monday | Keyboard-first navigation, ⌘K, fast inline edits, board/list toggle, compact density. **Adopted** for UI. |
| Bitrix24 | All-in-one breadth (CRM + tasks + HR + calendar). Confirms appetite for HR-lite inside a CRM. |
| Monica | Personal relationship notes; reminder-centric. Minor influence (follow-up dates on contacts). |

## 4. Libraries chosen

All vendored into `vendor/` from their official GitHub release tags (no Composer needed on the server):

| Need | Library | Why |
|---|---|---|
| SFTP | phpseclib 3.0.43 (+ paragonie/constant_time_encoding 3.0.0) | Pure PHP; works without the `ssh2` extension, which shared hosts rarely have |
| Email | PHPMailer 6.9.3 | Mature SMTP with TLS/STARTTLS, DKIM-ready |
| PDF | Dompdf 3.1.0 (+ php-font-lib, php-svg-lib, html5-php, php-css-parser) | Pure PHP HTML→PDF for invoices/quotes |
| S3 / Wasabi / R2 / Spaces / MinIO | **Own SigV4 implementation** (~250 lines) | AWS SDK is 50+ MB and needs Guzzle; a focused signer is auditable and tiny |
| UI reactivity | Alpine.js 3.17.5 (built locally from source) | Requirement; 54 KB |
| Charts | Chart.js 4.4.7 (built locally from source) | Requirement |
| CSS | Tailwind CSS 4.3 compiled at build time | No runtime Node needed; ships one static CSS file |
| Excel export | Own minimal XLSX writer on ZipArchive | Avoids PhpSpreadsheet's size |
| TOTP | Own RFC 6238 implementation | 60 lines, no dependency |

## 5. Key decisions and why

1. **Metadata-driven record engine** (from EspoCRM/Twenty) — 25+ entities share one list/board/detail/form/API/export/import implementation, so every control is real and consistent, and custom fields work everywhere.
2. **Zero mandatory daemons** — cron is a single `php cron.php` (or a secret URL for hosts without CLI cron). Queues are DB tables.
3. **Tenant isolation at the query layer** — every scoped query is built by `RecordService`, which always injects `company_id` and the permission scope. Controllers cannot bypass it.
4. **No "code" workflow action** — Odoo and Twenty allow arbitrary code in automations; that is a remote-code-execution risk on shared hosting.
5. **Signed updates** — the updater only installs packages whose SHA-256 is signed with the vendor's Ed25519 key (libsodium is bundled with PHP).

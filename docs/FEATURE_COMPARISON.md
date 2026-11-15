# Feature Comparison Matrix

Legend: ✅ available in the inspected open-source code · 🟡 partial · 💲 paid edition / paid extension · 🧩 separate app or third-party extension · ❌ missing.
"Verified" columns come from the inspected repositories; see RESEARCH.md for file references.

## 1. Functional coverage

| Capability | EspoCRM 10 | SuiteCRM 7.15 | ERPNext 17 | Odoo CE 20 | Twenty | **NextaCRM v2** |
|---|---|---|---|---|---|---|
| Leads | ✅ | ✅ | ✅ | ✅ (merged with opportunities) | 🟡 (People/Companies, no lead object) | ✅ + score, qualification, duplicate check |
| Customers / Contacts | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Deal pipeline + kanban | ✅ | 🟡 (list only) | ✅ | ✅ | ✅ | ✅ multiple pipelines, weighted value, MRR |
| Lead conversion | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ to customer + contact + deal |
| Products / services | 💲 (Sales Pack) | ✅ (AOS) | ✅ | ✅ | ❌ | ✅ |
| Quotations | 💲 (Sales Pack) | ✅ | ✅ | ✅ | ❌ | ✅ PDF, public link, accept with e-signature |
| Invoices + payments | 💲 | ✅ | ✅ | ✅ | ❌ | ✅ multi-currency, partial payments, recurring |
| Expenses | ❌ | ❌ | ✅ (HRMS/accounts) | ✅ (hr_expense) | ❌ | ✅ |
| Full double-entry accounting | ❌ | ❌ | ✅ | 🟡 (Accountant app 💲) | ❌ | ❌ by design (export instead) |
| Projects | 💲 (Project Mgmt ext.) | ✅ + Gantt | ✅ | ✅ | ❌ | ✅ milestones, members, budget, progress, Gantt |
| Tasks | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ subtasks, dependencies, kanban, timer, recurrence, checklists |
| Time tracking / billable | ❌ | ❌ | ✅ | ✅ (hr_timesheet) | ❌ | ✅ → invoice |
| Support tickets | ✅ (Cases) | ✅ (Cases + AOP portal) | ✅ (Issue) | 💲 (Helpdesk is Enterprise) | ❌ | ✅ threaded replies, internal notes |
| SLA policies | ❌ | 🟡 (business hours only) | ✅ | 💲 | ❌ | ✅ first response + resolution |
| Knowledge base | ✅ | ✅ (AOK) | 🟡 | 💲 (Knowledge) | ❌ | ✅ + public help centre |
| Contracts | 💲 | ✅ | ✅ | 🟡 | ❌ | ✅ e-signature, renewal reminders |
| HR: employees, departments | 🟡 (users only) | 🟡 (Employees) | 🧩 (HRMS app) | ✅ | ❌ | ✅ |
| Leave requests + approval | ❌ | ❌ | 🧩 | ✅ (hr_holidays) | ❌ | ✅ |
| Attendance | ❌ | ❌ | 🧩 (HRMS) | ✅ (hr_attendance) | ❌ | ✅ check-in/out, register, holidays |
| Payroll | ❌ | ❌ | 🧩 (HRMS) | 💲 (Payroll is Enterprise) | ❌ | ✅ LOP, PF, ESI, PT, TDS, payslip PDFs |
| Client portal | 💲 (Portal) | 🟡 (AOP cases) | ✅ | ✅ | ❌ | ✅ invoices, quotes, projects, tickets, files, pay online |
| Online payment gateways | ❌ | ❌ | ✅ | ✅ | ❌ | ✅ Razorpay, Stripe, PayPal (signed webhooks) |
| E-signature | 💲 (ext.) | ❌ | ❌ | 💲 (Sign) | ❌ | ✅ quotes and contracts, evidence certificate |
| E-mail to ticket / lead (IMAP) | ✅ | ✅ | ✅ | ✅ | 🟡 (Gmail/Outlook sync) | ✅ with reply threading |
| E-mail sequences | 💲 (Advanced Pack) | 🟡 (campaigns) | ✅ (Email Campaign) | 💲 (Marketing Automation) | ❌ | ✅ stop on reply, signed unsubscribe |
| WhatsApp | 🧩 | 🧩 | 🧩 | 💲 (WhatsApp is Enterprise) | ❌ | ✅ Meta, Twilio, Gupshup |
| Report builder + scheduled e-mail | 💲 (Advanced Pack) | ✅ (AOR) | ✅ | ✅ | ❌ | ✅ |
| Approval chains | 💲 (BPM) | 🟡 (workflow) | ✅ (Workflow) | 💲 (Approvals) | ❌ | ✅ multi-step, no self-approval |
| AI assistant | 🧩 | ❌ | 🧩 | 💲 | 🟡 | ✅ bring your own key, data-minimised |
| Extension marketplace | ✅ | ✅ | ✅ | ✅ | 🟡 | ✅ signed modules |
| Calendar | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ month/week/agenda |
| Document / file manager | 🟡 (Documents) | 🟡 | 🟡 | 💲 (Documents) | 🟡 (attachments) | ✅ central library + per-record attachments |
| Storage drivers | Local, S3 | Local | Local (S3 via app) | Local / DB | Local, S3 | **Local, S3, Wasabi, S3-compatible, FTP, SFTP** |
| Workflow automation | 💲 (Advanced Pack) | ✅ (4 actions) | ✅ (Server scripts) | ✅ (base_automation) | ✅ | ✅ no-code, 7 actions, run log |
| Outgoing webhooks | ✅ | ❌ | ✅ | ✅ (action) | ✅ | ✅ signed, retried |
| REST API | ✅ | ✅ (v8 JSON:API) | ✅ | 🟡 (XML-RPC/JSON-RPC) | ✅ (GraphQL + REST) | ✅ versioned REST + OpenAPI 3 |
| API tokens + scopes | ✅ | 🟡 OAuth2 | ✅ | ✅ | ✅ | ✅ hashed tokens, read/write scope |
| Custom fields | ✅ | ✅ (Studio) | ✅ | 💲 (Studio) | ✅ | ✅ 10 field types, all modules |
| Saved views | 🟡 | ✅ (saved search) | ✅ | ✅ | ✅ | ✅ private + shared |
| Global search | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ ⌘K, permission-aware |
| Import CSV / export | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ CSV import with mapping; CSV + XLSX export |
| Web-to-lead forms | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ embeddable, honeypot + rate limit |
| Multi-company | 🟡 | ❌ | ✅ | ✅ | 🟡 (workspaces) | ✅ |
| Multi-currency + rates | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| 2FA | ✅ TOTP/Email/SMS | ❌ | ✅ | ✅ TOTP | ✅ TOTP | ✅ TOTP + recovery codes |
| Audit log | ✅ | ✅ | ✅ | 🟡 (chatter tracking) | ✅ | ✅ field-level diffs |
| Web installer | ✅ | ✅ | ❌ (bench) | ❌ | ❌ (Docker) | ✅ 11 steps |
| One-click updater | ✅ | 🟡 (Upgrade Wizard) | ❌ (bench) | ❌ | ❌ | ✅ signature-verified |
| Runs on cPanel shared hosting | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ |
| Mobile-first UI | 🟡 | ❌ | 🟡 | 🟡 | 🟡 (desktop-first) | ✅ drawer + bottom nav + card lists |
| Dark mode | ✅ (themes) | ❌ | ✅ | 🟡 | ✅ | ✅ light/dark/system |

## 2. Quality assessment

| | Strengths worth adapting | Technical weaknesses observed |
|---|---|---|
| **EspoCRM** | Metadata entities; ACL levels; brute-force hooks per IP/user/code; clean REST | Key business modules (sales docs, BPM) are paid; only Local/S3 storage; bcrypt with md5-crypt fallback |
| **SuiteCRM** | Breadth (AOS/AOW/AOR); security groups | MD5 pre-hash of passwords; Smarty/legacy JS; desktop-only layouts; slow list views |
| **ERPNext** | Deep domain models (SLA, timesheet→invoice, task dependencies, lead qualification) | Needs Python/Frappe bench, Redis, workers; heavy for agencies; HR split out |
| **Odoo CE** | Rich automation triggers; weighted & recurring revenue; duplicate leads | Helpdesk, documents, approvals, Studio are Enterprise-only; PostgreSQL + workers |
| **Twenty** | Best modern UX (inline cells, board, command menu, saved views); SSRF-safe HTTP client; validated storage keys | Requires Node, Postgres, Redis, workers; no invoicing/projects/support |

## 3. Gaps NextaCRM fills that none of the five cover together

1. Agency-specific chain in one schema: **lead → deal → quote → project → tasks → billable time → invoice → payment**, with support tickets and SLA on the same customer.
2. Six storage back-ends with a "test connection" button and per-file driver tracking (files uploaded before a driver switch keep working).
3. Shared-hosting-grade security on by default: Argon2id, TOTP, per-IP/per-user/per-code lockout, CSP, SSRF-safe webhooks, encrypted secrets, signed updates.
4. Zero-daemon operation: one cron line or a secret cron URL.

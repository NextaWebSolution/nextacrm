# Development Roadmap

## Shipped

### v1.0 / 1.0.1
- Core framework, installer, updater, cron, security baseline
- CRM: leads, customers, contacts, deals/pipelines, products, quotes, invoices, payments, expenses, contracts, web-to-lead
- Delivery: projects, milestones, tasks (kanban, timer), time entries
- Support: tickets with SLA, knowledge base
- Team: employees, departments, leave approvals
- Calendar, files (6 storage drivers), notifications, global search, saved views, custom fields, import/export
- Workflow automation, webhooks, REST API v1 + OpenAPI
- Dashboard + reports; multi-company, multi-currency; dark mode; PWA manifest

### v2.0 (includes the v1.1 and v1.2 plans)
- ✅ Client portal with online payments (Razorpay, Stripe, PayPal)
- ✅ IMAP inbox → tickets and leads
- ✅ Gantt view, task recurrence, task checklists
- ✅ Contract renewal reminders; public help centre
- ✅ WhatsApp (Meta Cloud API, Twilio, Gupshup)
- ✅ E-signature on quotations and contracts
- ✅ E-mail sequences with unsubscribe
- ✅ Report builder with scheduled e-mails
- ✅ Multi-step approval chains for quotations and expenses
- ✅ Attendance and payroll (India-ready statutory rules)
- ✅ AI assistant with pluggable providers
- ✅ Module marketplace + SDK

## Next (v2.1 candidates)
- Two-way calendar sync (Google / Microsoft 365)
- Inbound WhatsApp messages via provider webhooks (conversation view on the record)
- Portal: quote acceptance and contract signing inside the portal, portal branding per company
- Payroll: state-wise professional-tax slabs, bank transfer file export, Form 16 data export
- Report builder: joins across related modules, dashboard widgets from saved reports
- AI: lead-scoring suggestions and meeting-note summaries (opt-in)
- Marketplace: module update notifications and one-click module upgrades

## Engineering practices
- Every schema change ships in `schema.php` **and** as a numbered migration in `app/Database/migrations/` (use `Migrator::syncSchema()` for additive changes).
- Release = signed ZIP + `latest.json`, built by `tools/release.php`. Full checklist: [RELEASE_PROCESS.md](RELEASE_PROCESS.md).
- Test suites: unit, end-to-end, attacker simulation (v1 + v2), class loading, browser installer and page checks — all must be green, plus an upgrade test from the previous release.

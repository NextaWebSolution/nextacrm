# Development Roadmap

## v1.0 (this release)
- Core framework, installer, updater, cron, security baseline
- CRM: leads, customers, contacts, deals/pipelines, products, quotes, invoices, payments, expenses, contracts, web-to-lead
- Delivery: projects, milestones, tasks (kanban, timer), time entries
- Support: tickets with SLA, knowledge base
- Team: employees, departments, leave approvals
- Calendar, files (6 storage drivers), notifications, global search, saved views, custom fields, import/export
- Workflow automation, webhooks, REST API v1 + OpenAPI
- Dashboard + 6 reports; multi-company, multi-currency; dark mode; PWA manifest

## v1.1 (next)
- Client portal (customers see projects, invoices, tickets, files; pay online)
- Payment gateway adapters (Razorpay, Stripe, PayPal) for invoice links
- IMAP inbox → tickets and lead capture
- Gantt view for projects, task recurrence and task checklists
- Contract renewal reminders; public knowledge-base help centre
- WhatsApp provider adapters (Meta Cloud API, Twilio, Gupshup)

## v1.2
- E-signature on quotes/contracts
- Email sequences / drip campaigns
- Report builder (custom grouping/aggregation) and scheduled report e-mails
- Approval chains (multi-step) for quotes/expenses

## v2.0
- Payroll and attendance
- AI assistant (lead summaries, reply drafting) via pluggable providers
- Marketplace for third-party modules

## Engineering practices
- Every schema change ships as a numbered migration in `app/Database/migrations/` and in `schema.php`.
- Release = signed zip + `latest.json`; `tools/release.php` builds and signs.
- Smoke tests: `php tools/smoke.php` runs installer + CRUD on every entity against SQLite.

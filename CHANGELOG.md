# Changelog

## 2.0.1 — 16 November 2026

**Fixes**
- Installing with demo data failed on Mondays: the sample leave request ran from "next Monday" to "next Tuesday", which on a Monday puts the end date before the start date. The end date is now always the day after the start. (Also present in 1.0.x.)

## 2.0.0 — 15 November 2026

Everything planned for v1.1, v1.2 and v2.0 ships in this release. Upgrading from 1.0.x is automatic: the updater installs the files and migration `0003_v2_features` adds 22 tables and 23 columns without touching existing data.

**Customers & payments (v1.1)**
- **Client portal** (`/portal`): customers' contacts sign in separately from staff and see their invoices (with PDF and Pay button), quotations, projects, tickets (create and reply) and files you choose to share. Invite or disable access from the contact page. Internal ticket notes are never shown.
- **Online payments** with **Razorpay** (Payment Links), **Stripe** (Checkout) and **PayPal** (Orders). Customers pay from the public invoice link or the portal; payments are recorded only after a verified callback or signed webhook, once, for the exact amount and currency requested. Keys are stored encrypted per company (Settings → Online payments).
- **E-mail to ticket** (IMAP/IMAPS, cron): new e-mails become tickets or leads, replies thread onto the right ticket by `[TKT-…]` in the subject, auto-replies and loops are ignored.
- **WhatsApp** messages from leads, contacts, customers, deals, invoices, quotations and tickets through Meta Cloud API, Twilio or Gupshup; also a workflow action.
- **Public help centre** (`/help`) for knowledge-base articles marked public, with safe Markdown rendering.
- **Tasks**: recurrence (daily → yearly, optional end date) and checklists; **Gantt** timeline on projects.
- **Contract renewal reminders**: notification + task before the end date, contracts marked Expired automatically.

**Sales & automation (v1.2)**
- **E-signatures** on quotations and contracts: drawn signature, typed name, e-mail, IP, user agent, time and a SHA-256 hash of the document; downloadable signature certificate; signature shown on the PDF.
- **E-mail sequences** (drip campaigns) for leads and contacts: steps with delays, automatic stop on reply, one-click unsubscribe (signed link, confirmed by POST), workflow action "Enrol in sequence".
- **Report builder**: choose module, filters, grouping (including by day/week/month/quarter/year), sum/average/count, chart type; save, share and e-mail reports on a schedule; CSV export.
- **Approval chains** (up to 5 steps, by role or user) for quotations and expenses above an amount. Requesters can't approve their own requests; edits that change the total reset the approval.

**Team, AI & platform (v2.0)**
- **Attendance**: check-in/check-out, monthly register, half-day rule, weekly offs, holidays, manual marking by HR; leave requests feed the register.
- **Payroll**: monthly runs with loss-of-pay from attendance, basic/HRA/special split, PF (with wage ceiling), ESI, professional tax and TDS; review, approve, mark paid (records the salary expense), e-mail payslip PDFs; employees see their own payslips.
- **AI assistant** (bring your own key — Anthropic Claude, OpenAI or any OpenAI-compatible server): summaries, next-step suggestions and reply drafts on leads, deals, customers, contacts, tickets and projects. Sensitive fields are never sent; e-mail addresses and phone numbers are masked unless an admin opts in; daily workspace limit and usage log.
- **Module marketplace**: install signed modules from the catalog or by upload, enable/disable/uninstall; Developer mode for unsigned modules (install-wide, with confirmation). SDK in `docs/MODULES.md`, sample module in `examples/modules/`, `tools/build-module.php` to package and sign.
- New extension registry (`App\Core\Extensions`) so features and modules plug into navigation, record pages, routes, cron, settings, permissions and workflow actions without editing core files.

**Fixes and hardening**
- Payment amounts are converted with each currency's real minor unit (JPY/KRW zero-decimal, KWD/BHD three-decimal) instead of always ×100.
- Upload-update form explains when a package is bigger than the server's `upload_max_filesize`/`post_max_size` instead of a generic error.
- E-mail sequence activity log stored the template text instead of the personalised message.
- `examples/` is blocked from web access like `docs/` and `tools/`; installed modules are excluded from release packages.
- Installer: developer-only SQLite mode (environment variable, PHP built-in server only) so the full web wizard can be tested automatically.

**Upgrade notes**
- Add your payment, IMAP, WhatsApp and AI keys under Settings. Webhook URLs to paste into Razorpay/Stripe/PayPal are shown on the Online payments page.
- Give roles access to the new **Attendance** and **Payroll** permissions (Settings → Roles); administrators have them automatically.
- Make sure cron runs every 5 minutes — inbox polling, sequences, renewals, scheduled reports and recurring tasks depend on it.

## 1.0.1 — 15 November 2026 (security release)

**Security**
- API: read-only tokens owned by administrators could write. Token scope is now enforced first (High).
- Admin user actions (reset 2FA, sign out, resend invite) are restricted to users of the current company; admins can only grant access to companies they belong to.
- Payments can't exceed the outstanding balance, and the invoice row is locked while a payment is recorded.
- Invoices: negative quantities, non-finite numbers and negative totals are rejected; paid or cancelled invoices are locked.
- Workflow loop protection (re-entry and depth guard).
- Public quotes: one response only; expired quotes can't be accepted.
- `X-Forwarded-For` parsed safely behind trusted proxies.
- Rate limits on password/2FA changes, uploads, import/export and failed API authentication.
- Secrets redacted from audit-log diffs; HTTPS enforcement (`force_https`); fail-closed encryption-key check; optional `NEXTACRM_*` environment overrides.
- Updater now removes files that a release no longer ships; migration 0002 removes obsolete files left by 1.0.0.
- Libraries updated: Dompdf 3.1.6, php-svg-lib 1.0.2, php-font-lib 1.0.2, phpseclib 3.0.58, PHPMailer 6.12.0. Development scripts removed from `vendor/`.

**Fixes**
- `cron.php` failed with a fatal error, so the scheduler did not run from cPanel cron or the secret URL.
- Mobile: horizontal overflow on the dashboard and Settings pages (bottom navigation labels were clipped).

**New**
- Scheduled purge of deleted records after a configurable retention period (Settings → Security, default 90 days).
- `docs/SECURITY_AUDIT.md`, `SECURITY.md`, `.env.example`, `config/config.example.php`.

## 1.0.0 — 15 November 2026

First release.

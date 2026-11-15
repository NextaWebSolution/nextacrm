<div align="center">

<a href="https://nextawebsolution.com"><img src=".github/assets/nexta-logo.png" alt="Nexta Web Solution logo" width="320"></a>

# NextaCRM

**Self-hosted CRM and business management for IT and service companies.**
Leads, deals, quotations, invoices, projects, timesheets, support tickets and HR in one PHP application that installs on ordinary cPanel hosting.

![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![MySQL 8 / MariaDB 10.6](https://img.shields.io/badge/MySQL-8.0%20%7C%20MariaDB%2010.6-4479A1?logo=mysql&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind%20CSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Alpine.js](https://img.shields.io/badge/Alpine.js-3-8BC0D0?logo=alpinedotjs&logoColor=white)
![cPanel ready](https://img.shields.io/badge/cPanel-ready-FF6C2C?logo=cpanel&logoColor=white)
![Version](https://img.shields.io/badge/version-2.0.0-4F46E5)

[Features](#features) · [Screenshots](#screenshots) · [Install](#installation) · [Hosting](#recommended-hosting) · [Security](#security) · [API](#rest-api) · [Docs](#documentation)

<img src=".github/assets/screenshots/dashboard.png" alt="NextaCRM dashboard showing pipeline, revenue and open tasks" width="100%">

</div>

## Why we built it

Most of our clients at Nexta Web Solution run small and mid-sized IT and service businesses. They were juggling one tool for leads, a spreadsheet for quotations, another app for invoices and a shared inbox for support. The big open-source suites can do all of that, but they usually need a VPS, Docker or a dedicated admin to keep them running.

NextaCRM covers the full journey, **lead → deal → quotation → project → timesheet → invoice → payment → support**, in a single application. You can install it from cPanel File Manager in about ten minutes. Before building it we reviewed EspoCRM, Odoo Community, ERPNext, Twenty and SuiteCRM. Our notes on what to keep and what to leave out are in [`docs/RESEARCH.md`](docs/RESEARCH.md).

## Features

**Sales**

- Leads with web-to-lead forms, scoring, duplicate detection and one-click conversion to customer, contact and deal
- Multiple deal pipelines on a drag-and-drop Kanban board with stage probabilities and weighted forecast
- Customers and contacts with activity timeline, notes, calls, meetings and files
- Quotations with PDF export and a public accept/decline link with **e-signature** for your client; converts to an invoice
- **E-mail sequences** (drip campaigns) with automatic stop on reply and one-click unsubscribe
- **WhatsApp** messages through Meta Cloud API, Twilio or Gupshup

**Delivery**

- Projects with members, budgets, milestones and progress
- Tasks with Kanban, priorities, due dates and dependencies (a task can't close while it's blocked)
- One-click timers and timesheets, then bill unbilled hours straight to an invoice
- **Gantt** timeline, recurring tasks and task checklists

**Finance**

- GST/VAT-ready invoices with taxes, discounts, partial payments and overdue tracking
- Recurring invoices generated automatically by the scheduler
- **Online payments** with Razorpay, Stripe and PayPal from the invoice link or the client portal, recorded automatically after a signed confirmation
- **Approval chains** for quotations and expenses (multi-step, by role or user)
- Payments, expenses, products and services, contracts with e-signature and **renewal reminders**
- Multi-currency with exchange rates and Indian number formatting (₹1,23,45,678.00)

**Support and team**

- Tickets with priorities, SLA targets and breach alerts, internal notes vs customer replies
- **E-mail to ticket** over IMAP with reply threading
- Knowledge base with a **public help centre**
- **Client portal**: customers see their invoices, quotations, projects, tickets and shared files, and pay online
- Employees, departments and leave requests with approval
- **Attendance** (check-in/out, monthly register, holidays) and **payroll** (LOP, PF, ESI, PT, TDS, payslip PDFs)
- Shared calendar for meetings, task due dates, leave, invoice due dates and expected deal closes

**Platform**

- Global search and command palette (<kbd>Ctrl</kbd> + <kbd>K</kbd>) that only shows what you're allowed to see
- List, board and detail views with inline editing, bulk actions, filters, saved views and column choice
- Custom fields on every module, with no database changes at runtime
- CSV and Excel import/export with a dry-run preview
- Workflow automation triggered on create, update, delete, field change, a date schedule, an incoming webhook or a manual run. Actions include creating tasks, updating fields, assigning by round-robin, sending e-mail, adding notes, notifying users and calling webhooks
- REST API v1 with OpenAPI 3 documentation, scoped tokens and signed outgoing webhooks
- Multi-company with separate numbering, currency and branding per company
- Dashboards and reports: sales, pipeline, invoice aging, time, tickets, lead sources, expenses and project profitability, plus a **report builder** with scheduled e-mail delivery
- **AI assistant** (bring your own Anthropic, OpenAI or self-hosted key): record summaries, next steps and reply drafts, with sensitive data kept out
- **Module marketplace**: signed add-ons that plug into navigation, record pages, cron, settings and workflows ([SDK](docs/MODULES.md))
- Light, dark and system themes, keyboard shortcuts, and a mobile layout with drawer and bottom navigation

**File storage drivers**

| Driver | Notes |
|---|---|
| Local | Default. Stored under `storage/uploads`, never served directly |
| Amazon S3 | AWS Signature V4, private bucket, short-lived signed URLs |
| Wasabi | S3-compatible |
| Cloudflare R2, DigitalOcean Spaces, Backblaze B2, MinIO | Any S3-compatible endpoint |
| FTP / FTPS | PHP `ftp` extension |
| SFTP | Password or private key (phpseclib) |

Each file remembers the driver it was saved on, so switching the default later won't break older files.

## Screenshots

<table>
  <tr>
    <td width="50%"><img src=".github/assets/screenshots/deals-pipeline.png" alt="Deal pipeline Kanban board in NextaCRM"><p align="center"><sub>Deal pipeline (Kanban)</sub></p></td>
    <td width="50%"><img src=".github/assets/screenshots/invoice.png" alt="GST invoice with payments and balance due"><p align="center"><sub>Invoice with payments and balance</sub></p></td>
  </tr>
  <tr>
    <td><img src=".github/assets/screenshots/project.png" alt="Project overview with tasks, time and budget"><p align="center"><sub>Project overview</sub></p></td>
    <td><img src=".github/assets/screenshots/tasks-board.png" alt="Task board with priorities and due dates"><p align="center"><sub>Task board</sub></p></td>
  </tr>
  <tr>
    <td><img src=".github/assets/screenshots/tickets.png" alt="Support tickets with SLA status"><p align="center"><sub>Support tickets and SLA</sub></p></td>
    <td><img src=".github/assets/screenshots/calendar.png" alt="Shared calendar with meetings and due dates"><p align="center"><sub>Calendar</sub></p></td>
  </tr>
  <tr>
    <td><img src=".github/assets/screenshots/command-palette.png" alt="Global search command palette"><p align="center"><sub>Global search (Ctrl K)</sub></p></td>
    <td><img src=".github/assets/screenshots/storage-settings.png" alt="Storage settings with S3, Wasabi, FTP and SFTP drivers"><p align="center"><sub>Storage drivers</sub></p></td>
  </tr>
  <tr>
    <td><img src=".github/assets/screenshots/client-portal.png" alt="Client portal showing invoices to pay and project progress"><p align="center"><sub>Client portal</sub></p></td>
    <td><img src=".github/assets/screenshots/report-builder.png" alt="Report builder grouping deal value by stage"><p align="center"><sub>Report builder</sub></p></td>
  </tr>
  <tr>
    <td><img src=".github/assets/screenshots/attendance.png" alt="Monthly attendance register with check-in and holidays"><p align="center"><sub>Attendance register</sub></p></td>
    <td><img src=".github/assets/screenshots/payroll.png" alt="Payroll run with PF, ESI, professional tax and TDS"><p align="center"><sub>Payroll with PF, ESI, PT and TDS</sub></p></td>
  </tr>
  <tr>
    <td><img src=".github/assets/screenshots/payment-settings.png" alt="Razorpay, Stripe and PayPal payment settings"><p align="center"><sub>Online payments</sub></p></td>
    <td><img src=".github/assets/screenshots/modules.png" alt="Module marketplace with signed modules"><p align="center"><sub>Modules</sub></p></td>
  </tr>
  <tr>
    <td><img src=".github/assets/screenshots/dashboard-dark.png" alt="NextaCRM dashboard in dark mode"><p align="center"><sub>Dark mode</sub></p></td>
    <td><img src=".github/assets/screenshots/reports.png" alt="Sales report"><p align="center"><sub>Reports</sub></p></td>
  </tr>
</table>

**On mobile**

<p align="center">
  <img src=".github/assets/screenshots/mobile-dashboard.png" alt="NextaCRM mobile dashboard" width="30%">&nbsp;
  <img src=".github/assets/screenshots/mobile-leads.png" alt="NextaCRM mobile lead list" width="30%">&nbsp;
  <img src=".github/assets/screenshots/mobile-deal.png" alt="NextaCRM mobile deal detail" width="30%">
</p>

## Requirements

| | Minimum |
|---|---|
| PHP | 8.2 (8.3 recommended) |
| Database | MySQL 8.0 or MariaDB 10.6 |
| Web server | Apache with `mod_rewrite`, LiteSpeed, or Nginx ([config](docs/INSTALL.md#5-nginx-vps)) |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `sodium`, `dom`, `json` (optional: `gd`, `intl`, `ftp`, `opcache`) |

You don't need Composer, Node.js, Redis, Docker or SSH on the server. Every PHP library is bundled in `vendor/`, and the CSS and JavaScript are already compiled.

## Installation

1. In cPanel, create a MySQL database and a user with all privileges on it.
2. Upload the release ZIP to `public_html` (or a sub-folder) and extract it. Make sure the hidden `.htaccess` file comes along.
3. Open your domain. The installer walks you through 11 steps: requirements, extensions, database, URL, administrator, company and modules. It then writes `config/config.php` and locks itself.
4. Add the scheduler under **cPanel → Cron Jobs**, running every 5 minutes:

   ```bash
   php /home/USERNAME/public_html/cron.php >/dev/null 2>&1
   ```

5. Set up SMTP under **Settings → Email** and turn on 2FA for your admin account.

The full guide in [`docs/INSTALL.md`](docs/INSTALL.md) covers HTTPS, Nginx, backups, restore, updates and a production checklist.

## Recommended hosting

<a href="https://thepowerhost.in/"><img src=".github/assets/tphost-banner.png" alt="Host NextaCRM on TPHOST by The PowerHost: NVMe SSD, LiteSpeed, Noida datacenter, 99.9% uptime SLA" width="100%"></a>

NextaCRM is built to run on standard cPanel hosting, and our recommended home for it is **[TPHOST (The PowerHost)](https://thepowerhost.in/)**, the hosting brand from the same team, with Indian datacenters including Noida. It's a strong fit when most of your team and clients are in India:

- NVMe SSD storage and LiteSpeed web server with LSCache
- 20–40 ms latency for visitors in India (as published by TPHOST)
- 99.9% uptime SLA, daily backups and Imunify360 protection
- Free SSL and free site migration
- Human engineers on 24×7 ticket and live chat support

Cloud and WordPress plans include cPanel, which is everything NextaCRM needs. See current plans and prices at **[thepowerhost.in](https://thepowerhost.in/)**.

## Security

Security was part of the design from the start, and v1.0 went through a full internal audit before release:

- Argon2id password hashing, TOTP two-factor login with recovery codes, and lockout after repeated failed logins (per IP and per account)
- Sessions stored server-side, tied to the browser, with idle and absolute timeouts and "sign out everywhere"
- Role-based permissions (view, create, edit, delete, export, import, approve, assign, manage) with *own*, *team* and *all* record scopes, enforced on the server for every page, form and API call
- Company isolation on every query, so data from one company never appears in another
- CSRF tokens, a strict Content-Security-Policy with no inline scripts, HSTS, `X-Frame-Options`, `nosniff`
- Server-side price, tax and total calculation, overpayment guards and row locking on payments
- Upload checks by extension, real MIME type and content, with random storage names and sandboxed downloads
- Webhooks protected against SSRF (private, loopback and metadata addresses blocked after DNS resolution)
- Secrets encrypted at rest (libsodium), API tokens stored only as hashes, full audit log
- Ed25519-signed updates with checksum verification and an automatic backup before every update

The audit report, with findings, fixes and test results, is in [`docs/SECURITY_AUDIT.md`](docs/SECURITY_AUDIT.md). The design is described in [`docs/SECURITY_PLAN.md`](docs/SECURITY_PLAN.md).

**Found a vulnerability?** Please don't open a public issue. Use GitHub's *Report a vulnerability* button on the Security tab, or contact us through [nextawebsolution.com](https://nextawebsolution.com). See [`SECURITY.md`](SECURITY.md).

## REST API

```bash
curl -H "Authorization: Bearer nx_your_token" \
     https://crm.example.com/api/v1/leads?per_page=25
```

- Create tokens under **Settings → API & webhooks**. Each token can be read-only or read/write, and can have an expiry date.
- Every module is available at `/api/v1/{module}` with list, read, create, update and delete. The API applies the same permissions as the web app.
- An interactive reference is served at `/api/docs`, and the OpenAPI 3 document at `/api/v1/openapi.json`.
- Outgoing webhooks are signed with HMAC-SHA256 (`X-Nexta-Signature`) and retried with backoff.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2+, own lightweight MVC with a metadata-driven record engine, PDO with prepared statements |
| Database | MySQL 8 / MariaDB 10.6 (InnoDB, utf8mb4) |
| Frontend | Server-rendered views, Tailwind CSS 4, Alpine.js 3, vanilla JavaScript, Chart.js |
| Libraries | PHPMailer, Dompdf, phpseclib (all bundled in `vendor/`) |

## Project structure

```text
app/
  Core/          Router, auth, sessions, permissions, crypto, storage drivers
  Entities/      One file per module: fields, views, permissions
  Services/      Business logic (sales, projects, payments, payroll, AI, workflows, updates…)
  Http/          Controllers for web, portal, settings and REST API
  Views/         PHP templates
  Database/      Schema and migrations
  features.php   Registers v2 features through the extension registry
assets/          Compiled CSS and JS, icons
modules/         Installed marketplace modules (not web-accessible)
examples/        Sample module for the SDK (repository only)
tests/           Unit, end-to-end, attacker-simulation and browser tests (repository only)
config/          Generated by the installer (not in the repository)
storage/         Uploads, logs, cache, backups (not web-accessible)
vendor/          Bundled PHP libraries
docs/            Architecture, database, security and install guides
```

## Documentation

| Document | What's inside |
|---|---|
| [INSTALL.md](docs/INSTALL.md) | Installation, cron, storage, Nginx, backups, updates, releases |
| [SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) | Request lifecycle, record engine, layers |
| [DATABASE_DESIGN.md](docs/DATABASE_DESIGN.md) | Every table, column and index |
| [SECURITY_PLAN.md](docs/SECURITY_PLAN.md) | Threat model and controls |
| [SECURITY_AUDIT.md](docs/SECURITY_AUDIT.md) | v1.0 audit and v2.0 review: findings and test results |
| [FEATURE_COMPARISON.md](docs/FEATURE_COMPARISON.md) | NextaCRM vs EspoCRM, Odoo, ERPNext, Twenty, SuiteCRM |
| [PRODUCT_REQUIREMENTS.md](docs/PRODUCT_REQUIREMENTS.md) | Scope and requirements |
| [RELEASE_PROCESS.md](docs/RELEASE_PROCESS.md) | How to ship the next update, step by step |
| [MODULES.md](docs/MODULES.md) | Module SDK and marketplace |
| [DEVELOPMENT_ROADMAP.md](docs/DEVELOPMENT_ROADMAP.md) | What's next |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |

## Roadmap

Version 2.0 delivered everything planned for v1.1, v1.2 and v2.0. Candidates for v2.1 include two-way calendar sync, inbound WhatsApp conversations, portal-side signing and state-wise payroll slabs. See the [full roadmap](docs/DEVELOPMENT_ROADMAP.md).

## License

NextaCRM is proprietary software © Nexta Web Solution Pvt. Ltd. Bundled third-party libraries keep their own licenses, listed in [`vendor/VERSIONS.md`](vendor/VERSIONS.md).

<div align="center">
<br>
<a href="https://nextawebsolution.com"><img src=".github/assets/nexta-logo.png" alt="Nexta Web Solution" width="180"></a>
<br>
<sub>Built in India by <a href="https://nextawebsolution.com">Nexta Web Solution</a> · Hosted on <a href="https://thepowerhost.in/">TPHOST</a></sub>
</div>

# NextaCRM v1.0 — Security Audit Report

**Date:** 15 November 2026 · **Scope:** entire application codebase (`app/`, `assets/js`, `vendor/`, installer, updater, cron, configuration, `.htaccess`) · **Method:** manual code review, dependency advisory check, and automated attacker-simulation tests against a local test installation. No real user data or external systems were accessed.

> This is an internal, AI-assisted audit. It does not replace an independent penetration test. Get one before processing online payments or highly sensitive personal data at scale.

## 1. Executive summary

NextaCRM's foundations were already strong: prepared statements throughout, an output-escaping discipline, Argon2id, TOTP 2FA, server-side RBAC with record scopes, tenant filtering in the record engine, CSRF tokens, a strict CSP, SSRF-safe outbound HTTP and signed updates.

The audit found **1 High, 6 Medium and 8 Low** issues. All 15 are fixed and verified by automated tests. The most serious was that a **read-only API token owned by an administrator could still create, edit and delete records**. Several medium issues were in **cross-company administration** and **invoice/payment integrity**. The bundled PDF and SFTP libraries also had published advisories, and both were upgraded.

**Verdict: `SAFE WITH CONDITIONS`.** The conditions are listed in section 9.

## 2. Vulnerability report and fixes

| # | Severity | Issue | Location | Impact | Status |
|---|---|---|---|---|---|
| 1 | **High** | Read-only API tokens ignored when the owner is an admin: the admin shortcut ran before the token-scope check | `app/Core/Permissions.php::level()` | A leaked "read-only" integration token of an admin allowed full write/delete via the API | **Fixed**: scope checked first. Test: *API read-only token cannot write* |
| 2 | Medium | Cross-company IDOR in admin user actions (`reset-2fa`, `logout`, `resend`) took a raw user ID with no company check | `Settings/UserController.php` | An admin of company B could disable 2FA on, or force sign-out of, users of company A | **Fixed**: target must belong to the current company. Tests: *rival admin cannot reset 2FA / sign out* |
| 3 | Medium | Admins could grant membership to any company (including themselves) and wipe memberships in companies they don't belong to; company lists exposed all company names | `UserController::update()`, `index()`, `CompanyController` | Privilege escalation across companies on a multi-company install | **Fixed**: limited to the acting admin's own companies. Test: *admin cannot add self to a company* |
| 4 | Medium | Vulnerable bundled libraries: Dompdf 3.1.0 (six advisories fixed in 3.1.6, including local-file read via SVG data-URI CVE-2026-56722, chroot bypass CVE-2026-55554, image-size DoS CVE-2026-59941/59942, file-existence oracles CVE-2026-55555/59943); phpseclib 3.0.43 (CVE-2026-32935 AES-CBC padding oracle, CVE-2026-40194, CVE-2026-84308 X25519 timing; fixed by 3.0.57+) | `vendor/` | PDF rendering of attacker-influenced content; crypto weaknesses in the SFTP client. Practical exposure was reduced (remote resources/PHP disabled in Dompdf, content escaped) but not eliminated | **Fixed**: Dompdf 3.1.6, php-svg-lib 1.0.2, php-font-lib 1.0.2, phpseclib 3.0.58, PHPMailer 6.12.0. Removed dev scripts from `vendor/` (`bin/`, examples, `get_oauth_token.php`). PDF/SFTP/mail smoke-tested |
| 5 | Medium | Payments could exceed the invoice balance, and simultaneous payments were not serialised | `EntityHooks::before_payments()` | Over-recorded revenue, wrong "Paid" status, reconciliation errors | **Fixed**: outstanding-balance guard plus `SELECT … FOR UPDATE` on the invoice (MySQL). Tests: overpayment, negative, INF, 3 concurrent payments |
| 6 | Medium | Invoice/quote line items accepted negative quantities, non-finite numbers and negative totals; paid/cancelled invoices could be edited; totals could drop below the amount already paid | `SalesService::save()/normalizeItems()` | Financial record manipulation; broken accounting | **Fixed**: validation and lock rules. Tests: negative qty, negative total, 900% discount, paid-invoice edit |
| 7 | Medium | Workflow recursion: "on update → update field" re-triggered itself without limit | `WorkflowService::execute()` | Request crash / CPU exhaustion (DoS) triggered by any record edit | **Fixed**: re-entry and depth guard (max 3). Test: *self-trigger loop contained* |
| 8 | Low | Public quote acceptance: double submission / replay and acceptance after `valid_until` | `PublicController::respond()` | Duplicate notifications; customers accepting expired prices | **Fixed**: atomic conditional update; expiry check. Tests: replay, expired |
| 9 | Low | Behind a trusted proxy, the client-controlled left-most `X-Forwarded-For` value was used as the client IP | `Request::ip()` | Login lockout and rate-limit bypass by spoofing the header | **Fixed**: right-to-left walk skipping trusted proxies |
| 10 | Low | No throttling on re-authentication (password change, 2FA enable/disable), uploads, import/export, or failed API auth | `routes.php`, `Middleware`, `ApiAuth` | Online guessing of current password from a hijacked session; resource abuse | **Fixed**: per-user/IP limits; API failure limiter. Test: bad tokens → 429 |
| 11 | Low | Audit diffs could store values of fields named password/secret/token | `Audit::diff()` | Secrets in audit log | **Fixed**: redacted as `[redacted]` |
| 12 | Low | HTTPS not enforced server-side (HSTS only once already on HTTPS) | `App::run()`, installer | First request over HTTP could be intercepted | **Fixed**: `force_https`, enabled by the installer only when the origin served HTTPS (prevents proxy redirect loops) |
| 13 | Low | No startup validation of the encryption key; no supported way to keep secrets in environment variables | `App::loadConfig()` | Running with a broken key; secrets only in files | **Fixed**: fail-closed key check; `NEXTACRM_*` env overrides; `.env.example`, `config/config.example.php` |
| 14 | Low | Release package included the CLI test installer with a demo admin password | `tools/release.php` | Default credentials if misused on a server | **Fixed**: excluded from release packages (CLI-only guard already present) |
| 15 | Low | The updater never deleted files that a newer release removed, so replaced libraries and dev scripts (e.g. `vendor/phpmailer/get_oauth_token.php`) stayed on upgraded sites | `UpdateService::installPackage()` | Outdated or vulnerable code lingering after updates | **Fixed**: updater prunes files in `app/`, `vendor/`, `assets/`, `tools/` that the new package doesn't contain (never `config/`/`storage/`); migration `0002` cleans up 1.0.0 installs. Tested: 1.0.0 → 1.0.1 upgrade left 0 stale files; planted stray files removed |

**Data deletion (added):** a scheduled purge now permanently erases deleted records together with their line items, timeline entries and stored files after a configurable retention period (default 90 days). Before this, deleted data stayed in the database indefinitely. Verified: records deleted 100 days ago were purged with their line items, and a record deleted today was kept.

**Functional bug found during the audit:** `cron.php` crashed with a fatal error (namespace alias on two lines), so the scheduler could not run from cPanel cron or the secret URL. Only the *Run now* button in Settings worked, because it calls the scheduler directly. That would have stopped queued e-mail, recurring invoices, reminders, overdue marking and the purge. **Fixed** and covered by three new tests.

Also fixed during the audit (not security): horizontal overflow on mobile for the dashboard and all Settings pages, which caused the browser to zoom out and clipped the bottom navigation labels.

## 3. Areas reviewed with no issues found

| Area | Evidence |
|---|---|
| SQL injection | All queries use PDO prepared statements; identifiers only come from entity metadata through `DB::ident()`; JSON keys regex-validated; every `IN (…)` list is integer-cast. API/list sort, filter and search fuzzing returned no SQL errors and tables stayed intact |
| XSS | 1,368 template echoes reviewed: user data goes through `e()`/`ej()` or escaping helpers; raw echoes are literals or validated values (colour regex, fixed lists). Stored/reflected payload tests are escaped. CSP has no `unsafe-inline` scripts |
| CSRF | All state-changing web routes are in the `csrf` group; forged and missing tokens rejected (nothing created). API uses bearer tokens only, no cookies, and sends no CORS headers, so other origins can't call it from a browser |
| Authentication | Argon2id with rehash; timing-equalised unknown-user path; per-IP and per-account lockout; 2FA with replay protection (last step stored); single-use, hashed, expiring reset tokens; all sessions revoked on reset/password change; no user enumeration in reset |
| Sessions | Server-side session records, regeneration on login and 2FA, UA binding, idle/absolute timeouts, `HttpOnly` + `SameSite=Lax` + `Secure` on HTTPS |
| Authorization / IDOR | Tenant `company_id` in every record query and lookup; relation fields validated against the same company; files authorised through their parent record; ownership checks on activities, saved views, sessions, tokens, webhooks, forms, workflows, teams, pipelines |
| Mass assignment | Only metadata-declared, non-system, non-readonly fields accepted; `company_id`/`created_by` set server-side (verified via API) |
| Uploads | Extension allowlist + blocklist + double-extension check; MIME sniffing; image re-validation; PHP/script content sniff; random storage names; downloads with `nosniff`, `attachment`, sandbox CSP |
| SSRF | Webhooks/HTTP client resolve DNS, block private/loopback/link-local/metadata/IPv6-mapped addresses, pin the resolved IP, no redirects, no non-HTTP schemes (tested incl. `localhost`, `169.254.169.254`, `[::1]`, `file://`, `gopher://`) |
| Exposed files | `config/`, `storage/`, `vendor/`, `app/`, `docs/`, `tools/`, dot-files, `manifest.json`, `*.md` blocked (tested) |
| Errors | Production never displays errors; JSON/HTML errors carry no stack traces, paths or SQL; request IDs in logs |
| Updates | Ed25519 signature + SHA-256 + path-traversal checks + backup + preserved `config/`/`storage/` (tested end-to-end) |

Not applicable: Supabase/RLS (MySQL with application-level tenant isolation), JWT (opaque hashed tokens instead), payment gateway webhooks (no gateway in v1.0, planned for v1.1).

## 4. Security test results

| Suite | Result | Notes |
|---|---|---|
| Attacker simulation (`tests/attack.py`) | **99 / 99 passed** | Cross-tenant IDOR (web + API), API scopes, mass assignment, SQLi, stored/reflected XSS, CSRF, stolen-cookie reuse, open redirect, brute force/lockout, reset enumeration, 8 malicious uploads, 14 private paths, overpayment/negative/INF/concurrent payments, invoice tampering, quote replay/expiry, 6 SSRF targets, workflow loop, page-size cap, scheduler entry points (CLI, secret URL, wrong key) |
| End-to-end functional (`tests/e2e.py`) | **249 / 249 passed** | Every module page, exports, PDF, kanban, conversions, timers, invoicing, payments, tickets, workflows, API, cron, backup |
| Unit (`tests/unit.php`) | **52 / 52 passed** | AWS SigV4 reference vector, RFC 6238 TOTP vectors, SSRF IP classes, storage key traversal, redirects, totals, CSV injection, update signatures, schema |
| Browser console (Chromium, desktop/dark/mobile) | **0 errors** | |
| Mobile overflow check (390 px) | **25 pages, 0 overflow** | |
| PHP lint | all files pass | |

Initial run of the new attack suite: 92/96. The four failures were finding #1 (real), one wrong test assumption (the Sales role has *Assign* by default), one redirect-vs-419 difference with nothing created, and a dev-server path artefact. All were resolved and re-run on a fresh install.

## 5. Secrets audit

| Item | Location | Status |
|---|---|---|
| Hardcoded API keys, cloud keys, payment keys, private keys | Whole codebase and release package | **None found** (regex sweep for AWS/Stripe/Razorpay/Slack/Google key formats; the only `BEGIN … PRIVATE KEY` strings are phpseclib doc comments) |
| Database credentials, encryption key | `config/config.php` (generated by installer) | Not in repository or release (`.gitignore` + release exclusions); web access blocked; optional env overrides |
| SMTP, storage, webhook secrets, TOTP secrets | Database | Encrypted with libsodium secretbox; never returned to the browser (forms show "saved — leave empty to keep") |
| API tokens, reset tokens | Database | SHA-256 hashes only; plain token shown once |
| Update signing secret key | Off-server (delivered to the owner separately) | Not in package (verified). **Action:** store offline; it was shared once through chat, so treat it as sensitive, or rotate it before the first public release (new key pair + new `update.public_key` in `manifest.json`) |
| Demo/test credentials | `tools/smoke-install.php` (CLI only) | Excluded from release packages |
| Git history | n/a (repository not yet created) | Start the repository from this clean tree |

## 6. Personal data flow map

| Data | Collected from | Stored | Who can access | Shared with | Deletion |
|---|---|---|---|---|---|
| Staff accounts: name, e-mail, password hash, 2FA secret (encrypted), recovery-code hashes | Invite + profile | `users` | Self; admins (no secrets displayed) | — | Admin "remove user" disables and renames the e-mail; sessions revoked |
| Login telemetry: IP, user agent (hashed in sessions), attempts | Every sign-in | `user_sessions`, `login_attempts`, `audit_logs` | Admins | — | Sessions and attempts cleaned by cron; audit log retained |
| Leads, customers, contacts: names, e-mail, phone, address, notes | Staff, imports, public web forms, API | Record tables + `custom` JSON | Per RBAC scope within company | Webhooks/workflow e-mails configured by admins; SMTP provider | Soft delete, then permanently purged by the scheduler after the retention period (default 90 days, Settings → Security) |
| Employees: personal details, salary | HR staff | `employees` | HR/admins; salary only with *manage* permission (hidden in UI and API otherwise) | — | Soft delete, then scheduled purge |
| Tickets: requester e-mail, messages | Staff (inbound e-mail is planned for v1.1) | `tickets`, `ticket_replies` | Support scope | SMTP provider | Soft delete, then scheduled purge |
| Files and attachments | Uploads | Local disk or configured S3/Wasabi/FTP/SFTP | Through parent-record permissions | Chosen storage provider | Deleted from storage on delete |
| Public quote acceptance: typed name, IP | Customer on public link | `quotes`, activity log | Sales scope | — | With the quote |
| Update check | Server | — | — | Update server receives only app version + PHP version | — |

In transit: HTTPS with HSTS and `force_https`; SMTP via TLS when configured; S3 over HTTPS; SFTP encrypted. At rest: secrets encrypted; database encryption depends on hosting.

## 7. Unresolved issues and residual risks

| Item | Why it remains | Recommendation |
|---|---|---|
| MySQL not exercised in this environment | No MySQL server was available; all tests ran on SQLite. MySQL DDL is generated from the same schema; the `FOR UPDATE` lock path only runs on MySQL | Run `tests/e2e.py` and `tests/attack.py` against a MySQL staging install before go-live |
| Alpine.js requires `'unsafe-eval'` in CSP | Standard Alpine build evaluates expressions | Move to the Alpine CSP build in v1.1. Current risk is low because there are no inline scripts and all output is escaped |
| Per-account lockout can be abused to lock a user out temporarily | Deliberate trade-off against password guessing | Keep it: the lockout expires automatically after 15 minutes |
| No "export / erase a person" (DPDP/GDPR) tool | Not in v1.0 scope | Add a data-subject export/erase action in v1.1 |
| Admins can point S3/FTP storage at arbitrary hosts | Admin-only configuration | Acceptable (admins are trusted); documented |
| Multi-company shares roles and admin rights across companies of one installation | By design: one installation is one organisation | Use separate installations for unrelated organisations |
| Independent review | AI-assisted audit | Commission a professional penetration test before enabling payment gateways |

## 8. Files changed during the audit

`app/Core/Permissions.php`, `app/Core/App.php`, `app/Core/Request.php`, `app/Core/Middleware.php`, `app/Core/RateLimiter.php`, `app/Core/Audit.php`, `app/Core/DB.php`, `app/Http/Controllers/Api/ApiAuth.php`, `app/Http/Controllers/PublicController.php`, `app/Http/Controllers/Settings/UserController.php`, `app/Http/Controllers/Settings/CompanyController.php`, `app/Services/SalesService.php`, `app/Services/EntityHooks.php`, `app/Services/WorkflowService.php`, `app/Services/InstallService.php`, `app/Services/CronService.php`, `app/Services/UpdateService.php`, `app/Database/migrations/0002_remove_obsolete_files.php`, `cron.php`, `app/Http/Controllers/Settings/SecurityController.php`, `app/routes.php`, `app/Views/dashboard.php`, 17 views in `app/Views/settings/`, `vendor/` (5 libraries), `tools/release.php`, `.gitignore`, `.env.example`, `config/config.example.php`, `SECURITY.md`, docs.

## 9. Deployment checklist

| Requirement | Status |
|---|---|
| Debug off in production (`'debug' => false` by default) | ✅ Pass |
| No secrets in code, package or repository | ✅ Pass |
| Security headers: CSP, HSTS (HTTPS), X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy | ✅ Pass |
| Private directories and files blocked by `.htaccess` | ✅ Pass (Nginx: use the config in `INSTALL.md`) |
| Installer locked after install | ✅ Pass |
| Dependencies free of known advisories at audit date | ✅ Pass |
| Rate limiting on login, 2FA, reset, re-auth, uploads, import/export, API, public forms | ✅ Pass |
| Server-side RBAC and tenant isolation on all routes and API | ✅ Pass |
| HTTPS enabled and `force_https` true | ⚠️ **Your action**: install over HTTPS |
| Cron job running every 5 minutes | ⚠️ **Your action** |
| 2FA enabled for all administrators (+ enforce setting) | ⚠️ **Your action** |
| SMTP with SPF/DKIM | ⚠️ **Your action** |
| Off-site backups | ⚠️ **Your action** |
| MySQL staging test of the full suite | ❌ **Pending**: not possible in the audit environment |
| Signing key stored offline (or rotated) | ⚠️ **Your action** |
| Independent penetration test before payment gateways | ⚠️ Recommended |

**Final verdict: `SAFE WITH CONDITIONS`.** Every finding is fixed and verified, and no known vulnerability remains. The code is ready for production use on HTTPS, provided the MySQL staging run passes and the action items above are completed.

## References

- Dompdf 3.1.6 security release and advisories: https://github.com/dompdf/dompdf/releases/tag/v3.1.6
- phpseclib advisories: https://github.com/phpseclib/phpseclib/security/advisories
- PHPMailer releases: https://github.com/PHPMailer/PHPMailer/releases

---

# Addendum — v2.0 security review (15 November 2026)

Scope: every feature added in 2.0 (portal, payments, e-sign, inbox, WhatsApp, help centre, sequences, report builder, approvals, attendance, payroll, AI assistant, module marketplace) plus the updater and installer changes. Method: code review against the same checklist as v1.0, an automated attacker suite (`tests/attack_v2.py`, 47 checks) on top of the v1 suite (`tests/attack.py`, 99 checks), unit tests (68), end-to-end tests, a class-loading check of all 124 classes, and browser-driven installer and upgrade tests.

## Controls by feature

| Area | Threat | Control (verified) |
|---|---|---|
| Client portal | Contact reads another customer's data (IDOR) | Every portal query filters on `company_id` **and** the contact's `customer_id`; tested for invoices, PDFs, tickets, replies |
| | Session confusion between staff and portal | Separate session key and `portal_sessions` table (hashed token, idle + absolute timeout, user-agent binding); staff session can't open the portal and vice versa |
| | Credential stuffing | Argon2id passwords, per-email + per-IP lockout, constant-time path for unknown e-mails, throttled reset/activate |
| | Leaking internal notes | Internal ticket replies excluded in SQL |
| Payments | Forged / replayed webhooks | Razorpay HMAC-SHA256, Stripe `t=,v1=` HMAC with 5-minute tolerance, PayPal verify-webhook-signature API; event IDs stored with a unique index; payment IDs unique per gateway; attempt rows locked during completion |
| | Amount tampering / under-payment | Recorded amount always comes from our own attempt row; gateway amount and currency must match exactly or the attempt goes to manual review |
| | Currency unit errors | Minor units per ISO 4217 exponent (zero- and three-decimal currencies) |
| | Secret exposure | Keys encrypted at rest (`SECRET_KEYS`), never rendered; webhook URL is per company |
| E-signature | Fake / empty signatures, stored XSS | PNG only, size and dimension limits, minimum ink check, name escaped everywhere; document SHA-256 stored with signer, IP, UA and time; one response per document (conditional update in a transaction) |
| Sequences | Unsubscribe link abuse | HMAC-signed token (company + e-mail), GET only shows a confirmation, POST with CSRF performs the opt-out |
| Inbox | Mail loops, header injection, HTML in tickets | Auto-reply/bulk detection, own-address skip, Message-ID dedupe; HTML converted to text; reply-to built from settings only |
| Help centre | XSS through Markdown | `markdown_safe()` escapes all HTML, allows only http(s)/mailto links (tested with `javascript:` and `<script>`) |
| Report builder | Field/SQL injection, cross-tenant data | Fields, operators and aggregates whitelisted from entity metadata; all queries through `RecordService::baseWhere` (tenant + permission scope); scheduled reports run as their creator |
| Approvals | Self-approval, bypass by editing | Requester can't decide; approver must match role/user of the current step; decisions locked with `SELECT … FOR UPDATE`; `approval_status` is not client-writable; total changes reset approval |
| Attendance & payroll | Salary data exposure | New `attendance`/`payroll` permissions; salary, PAN, UAN and bank fields marked sensitive (`manage`); users can only download their own payslips unless they hold payroll view |
| AI assistant | Data leakage to a third party, prompt injection, SSRF | Only the viewed record after a permission check; sensitive fields never sent; e-mails/phones masked by default; record text passed as quoted data with an instruction to ignore embedded instructions; plain-text output only; private network targets blocked unless an admin enables them; daily limit and usage log |
| Modules | Malicious or tampered packages | Ed25519 signature required (developer mode is install-wide, typed confirmation, and disables unsigned modules when turned off); all entries validated before extraction (no `..`, absolute paths, symlinks or unexpected extensions); staged extraction; per-module `Require all denied`; failing modules auto-disabled |
| Updater | Tampered package, partial update | Ed25519 + SHA-256, unsafe path rejection, maintenance mode, pre-update backup, `config/`, `storage/` and `modules/` preserved, stale files pruned; tested 1.0.1 → 2.0.0 → 2.0.1 with tamper attempts |
| Installer | Re-installation over a live site | Locked by `storage/installed.lock`; refuses to run if `config/config.php` exists; dev-only SQLite switch works only with the PHP built-in server and an environment variable |

## Issues found and fixed during this review

| # | Severity | Issue | Fix |
|---|---|---|---|
| V2-1 | High (availability) | `PaymentController::back()` clashed with the base controller method — every payment route returned a fatal error | Renamed to `returned()`; added `tests/load_all.php`, which loads every class to catch this class of error |
| V2-2 | High (financial) | Gateway amounts always multiplied by 100 — JPY/KRW would be charged 100×, KWD/BHD under-charged | ISO 4217 exponent-aware conversion for Razorpay and Stripe; PayPal decimal precision per currency |
| V2-3 | Medium | Blank signature pads were accepted | Minimum ink check (with GD) |
| V2-4 | Low | `examples/` directory reachable from the web | Blocked in `.htaccess`, Nginx sample and test router |
| V2-5 | Low | Attendance marked one day absent and treated all unmarked days as absent (unexpected loss of pay) | Unmarked days count as present unless "strict mode" is enabled |
| V2-6 | Low | AI daily-limit check ran before the permission check (minor information leak via error type) | Permission/tenant check first |

## Residual risks and limits

- **MySQL / MariaDB were not available in the build environment.** All automated suites ran on SQLite; the MySQL DDL is generated and unit-checked (InnoDB/utf8mb4, index name lengths) but the 2.0 installer and the 1.0.1 → 2.0.0 migration must be run once on a MySQL 8 / MariaDB 10.6 staging site before production rollout.
- Gateway, WhatsApp, IMAP and AI provider calls were tested with signed fixtures and local mocks; live sandbox credentials were not available. Run one sandbox payment per gateway after configuring keys.
- Modules run with full application privileges by design; the signature is the trust boundary.
- AI providers receive the minimised record text; customers in regulated sectors should review their provider's data-processing terms before enabling it.

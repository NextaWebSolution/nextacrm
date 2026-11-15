# Changelog

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

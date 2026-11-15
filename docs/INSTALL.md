# NextaCRM — Installation, Operations & Release Guide

## 1. Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 8.2 | 8.3 |
| Database | MySQL 8.0 or MariaDB 10.6 | MySQL 8.0+ |
| Web server | Apache 2.4 + mod_rewrite, or LiteSpeed (any cPanel host) | — |
| PHP extensions | pdo, pdo_mysql, mbstring, json, openssl, curl, fileinfo, zip, sodium, dom, ctype, filter, hash, session | + gd, intl, opcache, ftp |
| Memory | `memory_limit` 128M | 256M |

No SSH, Composer, Node.js, Redis, Docker or Supervisor needed. All libraries are bundled in `vendor/`.

## 2. Install on cPanel (public_html)

1. **Create the database.** cPanel → *MySQL® Database Wizard*: create a database and a user, give the user **ALL PRIVILEGES** on that database. Note the names (cPanel prefixes them, e.g. `acme_crm`).
2. **Upload.** cPanel → *File Manager* → `public_html` → *Upload* the release ZIP (e.g. `nextacrm-1.0.1.zip`) → *Extract*. Move the **contents** of the extracted `nextacrm/` folder into `public_html` (or keep them in a subfolder such as `public_html/crm` — subfolders work).
   Make sure the hidden file `.htaccess` was moved too (File Manager → *Settings* → *Show Hidden Files*).
3. **PHP version.** cPanel → *MultiPHP Manager* (or *Select PHP Version*): choose PHP 8.2+ and tick the extensions listed above (`sodium`, `zip`, `intl`, `fileinfo` are the ones most often unticked).
4. **Run the installer.** Open `https://your-domain/` — you are redirected to `/install`. The 11 steps:
   1. Welcome 2. Requirements (PHP, writable folders) 3. Extensions 4. Database credentials 5. Connection test 6. Application URL (auto-detected; HTTPS recommended) 7. Administrator account (strong password enforced) 8. Company (name, currency, timezone) 9. Modules to enable 10. Install (creates tables, roles, defaults; optional demo data) 11. Done.
5. The installer writes `config/config.php` (DB credentials + a random encryption key) and `storage/installed.lock`. While the lock exists the installer is **permanently disabled**; to reinstall you must delete the lock *and* the config by hand.
6. **Set up the cron job** (step 3 below) and **email** (Settings → Email).

> **Back up `config/config.php`.** It contains the `app_key` used to encrypt SMTP/storage passwords, API secrets and 2FA secrets. Losing it means those secrets must be re-entered and users must re-enrol 2FA.

### Permissions

Folders `config/` and `storage/` (and its subfolders) must be writable by PHP. On cPanel (suPHP/PHP-FPM as your user) the default `755` for folders and `644` for files is correct. Never use `777`.

### HTTPS

Enable *AutoSSL* in cPanel and run the installer over `https://`. NextaCRM then sends HSTS, uses `Secure` cookies and redirects HTTP to HTTPS (`'force_https' => true` in `config/config.php`, set automatically when the installer itself was reached over HTTPS).

Behind a reverse proxy or load balancer that terminates TLS, add its IP addresses to `'trusted_proxies'` in `config/config.php` so `X-Forwarded-Proto` is honoured; the header is ignored from any other address. Avoid Cloudflare "Flexible" SSL — use "Full (strict)" with an origin certificate.

## 3. Cron (scheduler)

cPanel → *Cron Jobs* → *Common settings: Once Per Five Minutes*:

```
php /home/CPANELUSER/public_html/cron.php >/dev/null 2>&1
```

(Use the exact PHP binary if needed, e.g. `/usr/local/bin/php` or `/opt/cpanel/ea-php83/root/usr/bin/php`.)

Hosts without CLI cron can call the secret URL shown in **Settings → System → Scheduler**:
`https://your-domain/cron.php?key=SECRET` (e.g. from an uptime monitor every 5 minutes).

The scheduler sends queued email, delivers webhooks with retries, runs time-based workflows, generates recurring invoices, marks overdue invoices, checks ticket SLAs, sends reminders, cleans expired sessions/tokens, permanently purges deleted records after the retention period (Settings → Security, default 90 days) and rotates backups. It holds a lock so overlapping runs are skipped.

## 4. File storage

Settings → Storage. Drivers: **Local** (default, `storage/uploads`, never directly web-accessible), **Amazon S3**, **Wasabi**, **S3-compatible** (Cloudflare R2, DigitalOcean Spaces, Backblaze B2, MinIO), **FTP/FTPS**, **SFTP** (password or private key). Use *Test connection* before saving. Each file remembers the driver it was stored on, so switching the default driver does not break older files. All downloads go through an authorised controller; S3-family drivers use short-lived pre-signed URLs.

Recommended S3 bucket policy: private bucket, no public ACLs, an IAM user limited to `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`, `s3:ListBucket` on that bucket.

## 5. Nginx (VPS)

```nginx
server {
    listen 443 ssl http2;
    server_name crm.example.com;
    root /var/www/nextacrm;
    index index.php;
    client_max_body_size 70m;

    location ~ ^/(app|config|storage|vendor|docs|tools)(/|$) { deny all; return 404; }
    location ~ /\.            { deny all; return 404; }
    location ~ \.(md|json|lock|sql|log|ini|sh|bak|dist)$ { deny all; return 404; }

    location /assets/ { try_files $uri =404; expires 30d; add_header Cache-Control "public, immutable"; }

    location = /cron.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/cron.php; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### Environment variables (VPS / Docker)

`config/config.php` is the default on cPanel. On a VPS you can keep secrets out of files by setting environment variables, which override the config file: `NEXTACRM_APP_KEY`, `NEXTACRM_URL`, `NEXTACRM_DB_HOST`, `NEXTACRM_DB_PORT`, `NEXTACRM_DB_NAME`, `NEXTACRM_DB_USER`, `NEXTACRM_DB_PASSWORD`, `NEXTACRM_TRUSTED_PROXIES`. See `.env.example` and `config/config.example.php`. Once installed, NextaCRM refuses to start if the encryption key is missing or invalid.

## 6. Production security checklist

- [ ] HTTPS enabled, Application URL uses `https://` and `force_https` is `true` in `config/config.php`.
- [ ] `config/config.php` has `'debug' => false` (default).
- [ ] Visiting `/config/config.php`, `/storage/`, `/vendor/`, `/app/` returns **403/404**.
- [ ] Cron job running (Settings → System shows "last run").
- [ ] 2FA enabled for every administrator (Settings → Security → *Require two-factor authentication for administrators*); encourage all users to enable it under Profile → Security.
- [ ] SMTP configured with SPF/DKIM for your domain.
- [ ] Off-site backups: download the latest from Settings → System → Backups, or point storage at S3.
- [ ] Install production **without** the demo-data option (demo records are for evaluation installs only).
- [ ] API tokens are scoped and have expiry dates; webhook endpoints use HTTPS.

## 7. Backups & restore

Settings → System → *Create backup* produces a ZIP of a full SQL dump plus `storage/uploads`. The updater also creates one automatically before every update. Backups are stored in `storage/backups` (not web-accessible) and rotated by cron (count configurable).

**Restore:** import `database.sql` from the ZIP into an empty database with phpMyAdmin, copy `uploads/` back into `storage/uploads`, and keep your original `config/config.php` (it holds the encryption key).

## 8. Updating

**Settings → System → Updates → Check for updates.** The updater downloads `latest.json` from the update server in `manifest.json`, then:

1. verifies the **Ed25519 signature** of `nextacrm|<version>|<sha256>` with the public key pinned in `manifest.json`,
2. downloads the package and verifies its **SHA-256**,
3. rejects any archive entry with unsafe paths,
4. enables maintenance mode and creates a **full backup**,
5. replaces application files (never `config/` or `storage/`),
6. runs pending database **migrations**, resets OPcache, and logs the result in the audit log.

**Manual update:** upload the new ZIP in Settings → System → Updates together with its version and signature (from `latest.json`) — the same signature check applies; or extract it over the installation via File Manager without overwriting `config/` and `storage/`, then open Settings → System to run migrations.

## 9. Publishing a release (vendor)

```
NEXTACRM_SIGNING_KEY=<base64 secret key> php tools/release.php --out=dist --notes="Bug fixes"
```

This builds `dist/nextacrm-<version>.zip` (excluding `config/config.php`, storage data and dev files), computes its SHA-256, signs it and writes `dist/latest.json`. Upload both to the update server URL in `manifest.json` (`update.server`). Bump `version` and `release_date` in `manifest.json` and add a migration file for any schema change first.

The signing **secret key must never be placed inside the application, the repository or the web server.** Keep it in a password manager / offline. If it is lost, ship a release whose `manifest.json` carries a new public key (installs must update manually once).

## 10. Developer notes

- CSS is pre-compiled (`assets/css/app.css`). After changing classes in views, rebuild with Tailwind v4: `node tools/build-css.mjs /path/to/node_modules/tailwindcss` (Node is only needed on a developer machine, never on the server).
- `php tools/gen-db-doc.php` regenerates `docs/DATABASE_DESIGN.md` from `app/Database/schema.php`.
- `php tools/smoke-install.php /abs/path/test.sqlite --demo` performs a scripted install against SQLite for local testing (CLI only; repository only, not shipped in release packages because it uses a demo password).
- REST API reference (OpenAPI 3.1) is served at `/api/docs` and `/api/v1/openapi.json` once installed.

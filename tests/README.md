# Test suites

These scripts are the release gate described in `docs/RELEASE_PROCESS.md`. They run against a **local SQLite test install** served by PHP's built-in server — never against a production site (the attack suites create users, tokens and records).

## Setup

```bash
# 1. a disposable copy of the app
cp -r . /tmp/nx-test && cd /tmp/nx-test
php tools/smoke-install.php /tmp/nx-test/storage/test.sqlite --demo
sed -i "s#'url' => '[^']*'#'url' => 'http://127.0.0.1:8099'#" config/config.php

# 2. serve it (tests/router.php emulates the .htaccess rules)
NX_SITE=/tmp/nx-test php -S 127.0.0.1:8099 -t /tmp/nx-test tests/router.php
```

The scripts were written for the build machine and use these constants — edit them at the top of each file if your paths differ:

| Constant | Default |
|---|---|
| Base URL | `http://127.0.0.1:8099` |
| Test install / SQLite file | `/home/claude/testsite`, `/home/claude/testsite/storage/test.sqlite` |
| Admin login | `admin@example.com` / `Str0ngPassw0rd!` (created by `tools/smoke-install.php`) |

## Suites

| Script | What it checks |
|---|---|
| `php tests/unit.php` | DDL generation, payroll maths, currency minor units, Markdown safety, helpers |
| `php tests/load_all.php [app-root]` | Loads every class (catches signature/compatibility fatals on untested routes) |
| `python3 tests/e2e.py` | Every page, CRUD on every module, CSRF, sessions, private paths, cron, backup |
| `php tests/attack_fixtures.php > /tmp/fx.json && python3 tests/attack.py` | Tenant isolation, IDOR, permission scopes, API token scopes, SSRF, payments maths, quote replay, uploads, rate limits |
| `python3 tests/attack_v2.py` | Portal isolation, payment webhook forgery/replay/under-payment, Stripe timestamp window, e-sign, unsubscribe tokens, approvals, HR/AI/module permissions |
| `node tests/installer.mjs` | Drives the 11-step web installer in Chrome (needs `NEXTACRM_INSTALL_SQLITE=/abs/path/web.sqlite` in the PHP server's environment and an un-installed copy) |
| `node tests/v2_pages.mjs` | Console errors and horizontal overflow on all v2 pages, desktop light + mobile dark, plus the client portal |
| `node tests/visual.mjs`, `tests/readme_shots*.mjs` | Screenshots for visual review and the README |

Browser scripts use Playwright with a local Chrome (`/usr/bin/google-chrome`).

The passwords in these files are fixtures for throwaway local installs only.

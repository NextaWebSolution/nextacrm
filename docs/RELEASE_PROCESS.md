# Release process — how to ship the next update

This is the checklist we follow for every NextaCRM release, from a one-line fix (2.0.1) to a feature release (2.1.0). Follow it in order. Nothing here requires root access on customer servers: customers receive updates through the built-in, signature-verified updater.

---

## 0. Versioning rules

| Change | Example | Version bump |
|---|---|---|
| Bug or security fix, no schema change | fix a view, tighten a check | patch: `2.0.0 → 2.0.1` |
| New feature, new columns/tables (backwards compatible) | new report, new module setting | minor: `2.0.1 → 2.1.0` |
| Breaking change (removed API field, PHP requirement raised) | drop PHP 8.2 | major: `2.x → 3.0.0` |

Security releases are always shipped as a patch on the current line, even if a feature release is in progress.

---

## 1. Branch and code

```bash
git checkout main && git pull
git checkout -b release/2.1.0
```

- Keep business rules on the server (services, hooks). Never rely on the browser for validation or tenant separation.
- Every query that touches company data must go through `RecordService` (`baseWhere`) or filter on `company_id` explicitly.
- New secrets (API keys, webhook secrets) go in `Settings::SECRET_KEYS` so they are encrypted at rest, and are never echoed back to the browser.
- New features that are optional should register through `app/features.php` (`Extensions::nav / panel / routes / cron / settingsTab / permission / workflowAction`) rather than editing core files.

## 2. Database changes

1. Add the table/column/index to **`app/Database/schema.php`** (this is the source of truth for fresh installs).
2. Add a numbered migration in **`app/Database/migrations/`**, e.g. `0004_v2_1_features.php`:

   ```php
   <?php
   return static function (App\Core\DB $db): void {
       App\Services\Migrator::syncSchema();   // creates any missing tables, columns and indexes
       // data back-fills go here (idempotent: safe to run twice)
   };
   ```

   **Keep migrations self-contained.** During a one-click or uploaded update, migrations run inside the PHP process of the *previous* version, so any class that request already loaded (`Permissions`, `Settings`, `Auth`…) is the old code. Hard-code the values a migration needs instead of calling application classes for them; the 1.0.1 → 2.0.0 upgrade test caught exactly this.

   `syncSchema()` only **adds** what is missing; it never drops or alters existing columns. If you must rename/drop, write explicit SQL for both MySQL and SQLite and a back-fill, and call it out in the changelog.
3. Regenerate the schema reference: `php tools/gen-db-doc.php` (updates `docs/DATABASE_DESIGN.md`).

Migrations run automatically after an update (one-click or upload) and are recorded in the `migrations` table, so each runs once.

## 3. Assets

Only needed if you changed classes in views or JavaScript:

```bash
node tools/build-css.mjs /path/to/node_modules/tailwindcss   # rebuilds assets/css/app.css
node --check assets/js/app.js
```

Node is a developer-machine tool only; servers never need it.

## 4. Bump the version

Edit **`manifest.json`**:

- `version` and `release_date`
- `release.version`, `release.notes`, `release.database_schema` (name of the newest migration)
- `compatibility.upgrade_from` — the versions this package can upgrade directly
- `requirements.php` if it changes

Add the release notes on top of **`CHANGELOG.md`** (Security / Fixes / New sections). Update `docs/DEVELOPMENT_ROADMAP.md` if roadmap items shipped.

## 5. Test

Run all suites against a fresh SQLite test install (see `docs/INSTALL.md §10`):

```bash
php tools/smoke-install.php /abs/path/test.sqlite --demo        # scripted install
php tests/unit.php                                               # pure logic: payroll, currency units, markdown, DDL
python3 tests/e2e.py                                             # every page and CRUD flow
python3 tests/attack.py && python3 tests/attack_v2.py            # tenant isolation, IDOR, CSRF, webhooks, e-sign…
php tests/load_all.php                                           # loads every class (catches signature fatals)
node tests/installer.mjs                                          # web installer, all 11 steps in a real browser
node tests/v2_pages.mjs                                           # console errors + mobile overflow, light/dark
```

Then the **upgrade test** — this is the one that protects customers:

1. Unpack the *previous* release package into a scratch folder and install it (with demo data).
2. Build the new package (step 6) and apply it through **Settings → System → Updates → upload** with the signature.
3. Confirm: version shows the new number, migrations ran, data is intact, every page loads, cron runs, installed modules are still present and enabled, files in `storage/uploads` untouched, and a backup was written to `storage/backups`.
4. Also try a tampered signature and a wrong version number — both must be refused.

**MySQL/MariaDB:** run the installer and the upgrade test on a real MySQL 8 / MariaDB 10.6+ database (e.g. a cPanel staging account) before publishing. The automated suites use SQLite.

## 6. Build and sign

```bash
php tools/release.php --key-file=/secure/offline/signing.secret --out=dist --notes="Short summary"
```

This produces:

- `dist/nextacrm-<version>.zip` — the update package (excludes `config/config.php`, storage data, installed modules, `.git`, dev tools and any `*.secret`)
- `dist/latest.json` — `{version, url, sha256, signature, requires_php, notes}`

Verify before publishing:

```bash
unzip -l dist/nextacrm-<version>.zip | grep -E "config/config.php|\.sqlite|secret|\.env$" && echo "STOP: sensitive file in package"
```

**The signing secret never goes into the repository, the package, a server, CI logs or chat.** Keep it offline (password manager / hardware key). If it is ever exposed, generate a new key pair, ship one release whose `manifest.json` carries the new public key, and ask customers to install that release manually once.

## 7. Publish

Releases are published to the **Nexta Update Hub** (`https://updates.nextawebsolution.com`, repository `NextaWebSolution/nexta-updates`). The hub verifies the signature against NextaCRM's public key, stores the ZIP, and updates `/nextacrm/latest.json` immediately. It never needs the signing key.

1. Publish with a publish token (Hub admin → NextaCRM → Publish tokens), kept in your password manager:

   ```bash
   export NEXTA_HUB_TOKEN=nxu_…
   php ../nexta-updates/tools/publish.php release --hub=https://updates.nextawebsolution.com --product=nextacrm \
       --zip=dist/nextacrm-<version>.zip --meta=dist/latest.json --notes-file=NOTES.md
   ```
   Add `--channel=beta` for a pre-release, and `--min-upgrade-from=X.Y.Z` when installations older than X.Y.Z must take an intermediate release first. You can also use the hub admin page (**Upload a release**: ZIP plus `latest.json`).
2. Check `https://updates.nextawebsolution.com/nextacrm/latest.json` and update a staging site with **Check for updates**.
3. Tag and push the source:

   ```bash
   git add -A && git commit -m "Release 2.1.0"
   git tag -a v2.1.0 -m "NextaCRM 2.1.0"
   git push origin main --tags
   ```
4. Create the GitHub release from the tag and attach the ZIP.

Modules: `php tools/build-module.php … --key-file=…`, then `php ../nexta-updates/tools/publish.php module --hub=… --product=nextacrm --zip=… --sig=…`.

## 8. After release

- Watch `storage/logs` on your own production install and the support inbox for 48 hours.
- If something is wrong: **withdraw** the version on the hub (installations stop being offered it within ~5 minutes) and fix forward with a patch release (2.1.1). The hub refuses to replace a published version.
- Customers who need to roll back restore the automatic backup from `storage/backups/pre-update-<old-version>-<date>.zip` (files) plus their database backup.

---

## Modules (marketplace) releases

Modules are versioned independently:

```bash
php tools/build-module.php path/to/module --key-file=/secure/offline/signing.secret --out=dist/modules
```

Publish the ZIP and `.sig` with the hub's `tools/publish.php module` (see section 7). The hub rebuilds `/nextacrm/modules/catalog.json` automatically. See `docs/MODULES.md`.

## Quick checklist

- [ ] Code + server-side validation + permission checks
- [ ] `schema.php` + numbered migration (idempotent) + `gen-db-doc.php`
- [ ] CSS/JS rebuilt if needed
- [ ] `manifest.json` version/date/release notes, `CHANGELOG.md`
- [ ] unit, e2e, attack, attack_v2, load_all, installer, page checks all green
- [ ] Upgrade test from the previous release (+ tamper tests)
- [ ] MySQL/MariaDB staging install + upgrade
- [ ] `tools/release.php` with the offline key; package contents checked
- [ ] Publish to the update hub (`tools/publish.php`); verify `latest.json`; tag + push + GitHub release

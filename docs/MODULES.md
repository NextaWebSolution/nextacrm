# Module SDK and marketplace

Modules add features to NextaCRM without editing core files, so they survive updates. A module is a folder with a `module.json` manifest and a `module.php` entry file, packaged as a ZIP and signed.

> Modules run with the same rights as NextaCRM itself. Only signed modules are accepted unless an administrator turns on **Developer mode** (Settings → Modules), which is an install-wide switch meant for development servers.

## Folder layout

```
my-module/
  module.json        required — manifest
  module.php         required — loaded on every request while the module is enabled
  install.php        optional — return fn (App\Core\DB $db) { … }  (runs on install and on upgrade)
  uninstall.php      optional — return fn (App\Core\DB $db) { … }  (runs before the files are removed)
  views/             optional — PHP templates (register with Extensions::viewDir)
  entities/          optional — extra entity definitions (register with Extensions::entityDir)
  README.md
```

Allowed file types: `php json md txt css js svg png jpg jpeg webp csv htaccess`. Paths may only contain letters, digits, `_ - . /`. Symlinks, `..`, absolute paths and other extensions are refused before anything is extracted. Maximum 10 MB and 500 files. Each installed module folder gets a `Require all denied` `.htaccess`, and the `modules/` folder is blocked from the web — serve assets through a route if you need them.

## module.json

```json
{
  "slug": "birthday-reminders",
  "name": "Birthday reminders",
  "version": "1.0.0",
  "author": "Nexta Web Solution",
  "description": "Creates a task a few days before each employee's birthday.",
  "requires": { "nextacrm": "2.0.0" }
}
```

- `slug`: lowercase letters, digits and dashes, 2–40 characters; it is the folder name.
- `version`: `x.y.z`. Installing a higher version of an installed slug upgrades it in place.
- `requires.nextacrm`: minimum NextaCRM version.

## module.php — the extension API

Every hook lives on `App\Core\Extensions` (aliased `X` below). Register hooks at the top level of `module.php`; do the work inside the callbacks.

| Call | What it does |
|---|---|
| `X::nav($group, $label, $path, $icon, $module = null, $action = 'view', $order = 50)` | Sidebar link in a group (Sales, Marketing, Delivery, Support, Finance, Team). Hidden when the user lacks `$module.$action`. |
| `X::routes(fn (App\Core\Router $r) => …)` | Register routes. Wrap them in `$r->group(['auth', 'csrf'], …)`; add `'admin'` or `'perm:module.action'` as needed. |
| `X::panel($entity, 'main'\|'side', fn (array $record, $svc, string $entity) => string, $order)` | A card on a record page. Return HTML (use `View::partial`). The record is already permission-checked. |
| `X::cron($name, fn () => mixed, $daily = false, $perCompany = false)` | Scheduled job. With `$perCompany = true` it runs once per company with that company's settings loaded (`Auth::companyId()`). |
| `X::settingsTab($group, $key, $path, $label, $icon)` | A link in the Settings navigation. |
| `X::permission($key, $label)` | A permission module shown in the role editor (`can($key, 'view')`…). |
| `X::workflowAction($type, $label, fn ($wf, $entity, $record, $action) => mixed)` | A new action in workflow automation. |
| `X::viewDir($prefix, __DIR__ . '/views')` | Makes `View::partial('prefix/name')` load `views/name.php`. |
| `X::entityDir(__DIR__ . '/entities')` | Adds metadata-driven entities (list/board/detail/API come for free). Create their tables in `install.php`. |

Useful helpers available everywhere: `App\Core\App::db()`, `Auth::id()`, `Auth::companyId()`, `can($module, $action)`, `setting($key, $default)`, `e()` (escape), `url()`, `money()`, `fmt_date()`, `icon()`, `App\Services\RecordService::for($entity)`, `App\Services\ActivityService::log(...)`, `App\Services\NotificationService::notify(...)`, `App\Core\Mailer::queue(...)`.

### Rules for module authors

- Start every PHP file with `defined('NX_ROOT') || exit;`.
- Always filter by `company_id = Auth::companyId()` (or use `RecordService`, which does it for you along with permission scopes). Never trust IDs from the browser without that filter.
- Escape output with `e()`. Use prepared statements (`$db->all($sql, $params)`), never string-concatenated SQL.
- State-changing routes must be in a `csrf` group and check permissions server-side.
- Store secrets with `Settings::set()`; never print them back.
- A module that throws while loading is disabled automatically and the error is logged — the rest of the CRM keeps working.

## Example

`examples/modules/birthday-reminders/` is a complete working module (panel on employee pages + daily per-company cron that creates tasks). Build it:

```bash
php tools/build-module.php examples/modules/birthday-reminders --out=dist/modules                       # unsigned (Developer mode only)
php tools/build-module.php examples/modules/birthday-reminders --key-file=/secure/signing.secret --out=dist/modules   # signed
```

Then upload the ZIP (and paste the contents of the `.sig` file) in **Settings → Modules → Upload a module**, and click **Enable**.

## Signing and the marketplace catalog

The signature is Ed25519 over `nextacrm-module|<slug>|<version>|<sha256 of the zip>`, made with the same vendor key as core updates (public key in `manifest.json` → `update.public_key`). `tools/build-module.php` also writes a catalog entry:

```json
{ "slug": "...", "name": "...", "version": "...", "description": "...", "requires": {...},
  "download_url": "https://updates.nextawebsolution.com/nextacrm/modules/<slug>-<version>.zip",
  "sha256": "...", "signature": "..." }
```

The marketplace feed (`manifest.json` → `marketplace.catalog`) is `{"modules": [ …entries… ]}`. **Browse marketplace** in Settings → Modules lists it; **Install** downloads over HTTPS, checks the SHA-256 and the signature, then installs exactly like an upload.

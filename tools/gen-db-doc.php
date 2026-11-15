<?php
/**
 * Generates docs/DATABASE_DESIGN.md from app/Database/schema.php (the single source of truth).
 * Usage: php tools/gen-db-doc.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('NX_ROOT', dirname(__DIR__));
require NX_ROOT . '/app/bootstrap.php';

$schema = App\Core\Schema::load();
$src = file_get_contents(NX_ROOT . '/app/Database/schema.php');
// Section headings come from the "// ─── Name ───" comments in schema.php
$sections = [];
$current = 'Other';
foreach (explode("\n", $src) as $line) {
    if (preg_match('/\/\/ ─+ (.+?) ─+/u', $line, $m)) { $current = trim($m[1]); }
    if (preg_match("/->create\\('([a-z_0-9]+)'/", $line, $m)) { $sections[$current][] = $m[1]; }
}

$names = [];
foreach ($schema->tables as $t) {
    foreach ($t->indexes as $ix) {
        $n = substr($ix['name'], 0, 60);
        if (isset($names[$n])) { fwrite(STDERR, "Index name collision: $n\n"); exit(1); }
        $names[$n] = true;
    }
}

$type = function (App\Core\SchemaColumn $c): string {
    return match ($c->type) {
        'id' => 'BIGINT UNSIGNED PK AI',
        'fk' => 'BIGINT UNSIGNED',
        'string' => 'VARCHAR(' . ($c->extra['len'] ?? 191) . ')',
        'decimal' => 'DECIMAL(' . $c->extra['p'] . ',' . $c->extra['s'] . ')',
        'bool' => 'TINYINT(1)',
        'int' => 'INT',
        'bigint' => 'BIGINT',
        'text' => 'TEXT',
        'longtext' => 'LONGTEXT',
        'date' => 'DATE',
        'datetime' => 'DATETIME',
        default => strtoupper($c->type),
    };
};

$tableCount = count($schema->tables);
$colCount = array_sum(array_map(fn ($t) => count($t->columns), $schema->tables));
$ixCount = count($names);

$md = "# NextaCRM — Database Design\n\n";
$md .= "> Generated from `app/Database/schema.php` by `php tools/gen-db-doc.php`. Do not edit by hand.\n\n";
$md .= "**$tableCount tables · $colCount columns · $ixCount indexes** — MySQL 8.0+/MariaDB 10.6+ (InnoDB, utf8mb4_unicode_ci). SQLite is supported for development/testing only.\n\n";
$md .= <<<MD
## Conventions

| Convention | Rule |
|---|---|
| Primary keys | `id BIGINT UNSIGNED AUTO_INCREMENT` on every table |
| Tenancy | Every business table has `company_id`; the record engine (`RecordService::baseWhere`) always adds `company_id = ?` server-side. Frontend filters are never trusted for isolation. |
| Ownership | Assignable records carry `owner_id` and `team_id`, used for the RBAC record scopes `own` / `team` / `all`. |
| Money | `DECIMAL(15,2)` + a `currency` (ISO 4217) column and, where relevant, `exchange_rate` to the company base currency. Floats are never used for money. |
| Time | All `DATETIME` values are stored in UTC (`SET time_zone = '+00:00'` on connect) and converted to the user's timezone on display. `DATE` columns are calendar dates. |
| Soft delete | Business records have `deleted_at`. Deleted records disappear from the app immediately and are permanently purged by the scheduler (with their line items, timeline and files) after the retention period in Settings → Security (default 90 days). Until then they can be recovered from the database or a backup. |
| Custom fields | Stored in the per-record `custom` JSON column; definitions live in `custom_fields`. No ALTER TABLE at runtime, so cPanel users never need schema privileges after install. |
| Audit columns | `created_by`, `updated_by`, `created_at`, `updated_at`. Field-level change history goes to `audit_logs`. |
| Secrets | API secrets, SMTP/storage credentials and TOTP secrets are encrypted (libsodium secretbox, key in `config/config.php`). API tokens and password-reset tokens are stored only as SHA-256 hashes; passwords as Argon2id. |
| Foreign keys | Referential integrity is enforced in the service layer (with dependency checks before delete) rather than DB constraints, so soft delete, restore and per-tenant import work consistently on shared hosts. All FK columns are indexed. |
| Migrations | Versioned PHP files in `app/Database/migrations`, tracked in the `migrations` table; the updater runs pending ones after a backup. |

## Relationship overview

```
companies 1─* users ─* company_user *─1 companies        (multi-company membership)
roles 1─* users      teams *─* users (team_user)
leads ──convert──▶ customers 1─* contacts
customers 1─* deals 1─* quotes ──convert──▶ invoices 1─* payments
quotes / invoices 1─* line_items (parent_type + parent_id) ◀── products
pipelines 1─* pipeline_stages ◀── deals
customers 1─* projects 1─* milestones 1─* tasks 1─* time_entries ──bill──▶ invoices
projects *─* users (project_members)
customers 1─* tickets 1─* ticket_replies      kb_articles (public help centre)
departments 1─* employees 1─* leave_requests
any record ─* activities / files / audit_logs / notifications (entity + record_id)
workflows ─* workflow_runs     webhooks ─* webhook_deliveries     api_tokens
```

Polymorphic tables (`activities`, `files`, `audit_logs`, `notifications`) reference a record by `entity` (e.g. `deals`) + `record_id`, indexed together.


MD;

$byName = [];
foreach ($schema->tables as $t) { $byName[$t->name] = $t; }
$md .= "## Tables\n\n";
foreach ($sections as $section => $tables) {
    $md .= "- **$section:** " . implode(', ', array_map(fn ($n) => "[`$n`](#$n)", $tables)) . "\n";
}
$md .= "\n";
foreach ($sections as $section => $tables) {
    $md .= "## $section\n\n";
    foreach ($tables as $name) {
        $t = $byName[$name] ?? null;
        if (!$t) { continue; }
        $md .= "### $name\n\n| Column | Type | Null | Default |\n|---|---|---|---|\n";
        foreach ($t->columns as $c) {
            $def = $c->hasDefault ? ($c->default === null ? 'NULL' : '`' . var_export($c->default, true) . '`') : '';
            $def = str_replace("'", '', $def);
            $md .= "| `{$c->name}` | {$type($c)} | " . ($c->null || $c->type === 'id' ? ($c->type === 'id' ? 'no' : 'yes') : 'no') . " | $def |\n";
        }
        if ($t->indexes) {
            $md .= "\n**Indexes:** " . implode(' · ', array_map(fn ($ix) => ($ix['type'] === 'unique' ? 'UNIQUE ' : '') . '(' . implode(', ', $ix['cols']) . ')', $t->indexes)) . "\n";
        }
        $md .= "\n";
    }
}
file_put_contents(NX_ROOT . '/docs/DATABASE_DESIGN.md', $md);
echo "Wrote docs/DATABASE_DESIGN.md ($tableCount tables, $colCount columns, $ixCount indexes)\n";

<?php defined('NX_ROOT') || exit;
$modules = [];
foreach (App\Services\EntityRegistry::all() as $d) { $modules[$d['module']] = $d['module_label'] ?? $d['label']; }
?><!doctype html>
<html lang="en" class="h-full" data-theme="system" style="--brand:#4f46e5">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title>Install <?= e($manifest['name'] ?? 'NextaCRM') ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script><script defer src="<?= e(asset('js/app.js')) ?>"></script><script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="min-h-full bg-app antialiased">
<div class="mx-auto max-w-3xl px-4 py-8 sm:py-14" x-data="installer(<?= ej(['checks' => $requirements, 'url' => $defaultUrl, 'modules' => $modules]) ?>)">
  <div class="mb-8 flex items-center gap-3"><span class="grid size-10 place-items-center rounded-xl bg-primary-600 text-white"><?= icon('layers', 'size-5') ?></span><div><h1 class="text-lg font-semibold"><?= e($manifest['name'] ?? 'NextaCRM') ?> setup</h1><p class="text-xs text-muted">Version <?= e($manifest['version'] ?? '1.0.0') ?></p></div></div>

  <ol class="mb-6 flex gap-1" aria-label="Progress"><template x-for="(s, i) in steps" :key="i"><li class="h-1.5 flex-1 rounded-full transition-colors" :class="i <= step ? 'bg-primary-500' : 'bg-[var(--border)]'" :title="s"></li></template></ol>
  <p class="mb-2 text-xs font-medium uppercase tracking-wider text-faint">Step <span x-text="step + 1"></span> of <span x-text="steps.length"></span> · <span x-text="steps[step]"></span></p>

  <div class="card p-6 sm:p-8">
    <template x-if="error"><div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" x-text="error"></div></template>

    <section x-show="step === 0">
      <h2 class="text-xl font-semibold">Welcome</h2>
      <p class="mt-2 text-sm text-muted">This wizard checks your server, connects to MySQL, creates the database tables, your administrator account and your company. It takes about a minute.</p>
      <div class="mt-5 rounded-lg bg-surface-2 p-4 text-sm"><p class="font-medium">Before you start, create in cPanel:</p><ul class="mt-2 list-disc space-y-1 pl-5 text-muted"><li>A MySQL database (MySQL 8.0+ or MariaDB 10.6+)</li><li>A database user with ALL PRIVILEGES on it</li></ul></div>
    </section>

    <section x-show="step === 1 || step === 2" x-cloak>
      <h2 class="text-xl font-semibold" x-text="step === 1 ? 'System requirements' : 'PHP extensions'"></h2>
      <ul class="mt-4 divide-theme rounded-lg border border-theme">
        <template x-for="c in (step === 1 ? checks.filter(c => ['PHP','Folders','Server'].includes(c.group)) : checks.filter(c => ['Extensions','Optional'].includes(c.group)))" :key="c.label">
          <li class="flex items-center gap-3 px-3.5 py-2 text-sm"><span :class="c.ok ? 'text-emerald-600' : (c.required ? 'text-red-600' : 'text-amber-500')" x-html="c.ok ? '<?= e(icon('circle-check', 'size-4')) ?>' : '<?= e(icon('circle-x', 'size-4')) ?>'"></span><span class="flex-1" x-text="c.label"></span><span class="text-xs text-muted" x-text="c.value"></span></li>
        </template>
      </ul>
      <button type="button" class="btn btn-ghost btn-sm mt-3" @click="recheck()"><?= icon('refresh-cw') ?>Re-check</button>
    </section>

    <section x-show="step === 3 || step === 4" x-cloak>
      <h2 class="text-xl font-semibold">Database</h2>
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div><label class="label">Host</label><input class="input" x-model="db.host"></div>
        <div><label class="label">Port</label><input class="input" type="number" x-model.number="db.port"></div>
        <div><label class="label">Database name</label><input class="input" x-model="db.database" placeholder="cpaneluser_crm"></div>
        <div><label class="label">Username</label><input class="input" x-model="db.username" autocomplete="off"></div>
        <div class="sm:col-span-2"><label class="label">Password</label><input class="input" type="password" x-model="db.password" autocomplete="new-password"></div>
      </div>
      <template x-if="testing"><p class="mt-4 flex items-center gap-2 text-sm text-muted"><?= icon('loader-circle', 'size-4 animate-spin') ?>Testing connection…</p></template>
      <template x-if="dbMsg"><p class="mt-4 rounded-lg px-3.5 py-2.5 text-sm" :class="dbOk ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300'" x-text="dbMsg"></p></template>
    </section>

    <section x-show="step === 5" x-cloak>
      <h2 class="text-xl font-semibold">Application URL</h2>
      <p class="mt-1 text-sm text-muted">The public address of this installation (used in e-mails, invoice links and the API). Use https if you have SSL.</p>
      <input class="input mt-4" x-model="url">
    </section>

    <section x-show="step === 6" x-cloak>
      <h2 class="text-xl font-semibold">Administrator account</h2>
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div><label class="label">Full name</label><input class="input" x-model="admin.name"></div>
        <div><label class="label">E-mail</label><input class="input" type="email" x-model="admin.email"></div>
        <div><label class="label">Password</label><input class="input" type="password" x-model="admin.password" autocomplete="new-password"><p class="help">At least 10 characters, letters and numbers.</p></div>
        <div><label class="label">Confirm password</label><input class="input" type="password" x-model="admin.password_confirmation" autocomplete="new-password"></div>
      </div>
    </section>

    <section x-show="step === 7" x-cloak>
      <h2 class="text-xl font-semibold">Your company</h2>
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div><label class="label">Company name</label><input class="input" x-model="company.name" placeholder="Nexta Web Solution Pvt. Ltd."></div>
        <div><label class="label">Application name</label><input class="input" x-model="company.app_name"></div>
        <div><label class="label">Country</label><input class="input" x-model="company.country"></div>
        <div><label class="label">Base currency</label><select class="select" x-model="company.currency"><?php foreach (['INR', 'USD', 'EUR', 'GBP', 'AED', 'AUD', 'CAD', 'SGD', 'SAR', 'ZAR', 'NZD', 'BDT', 'NPR', 'LKR'] as $c): ?><option><?= $c ?></option><?php endforeach; ?></select></div>
        <div><label class="label">Time zone</label><select class="select" x-model="company.timezone"><?php foreach ($timezones as $tz): ?><option><?= e($tz) ?></option><?php endforeach; ?></select></div>
        <div><label class="label">Tax ID / GSTIN (optional)</label><input class="input" x-model="company.tax_id"></div>
      </div>
    </section>

    <section x-show="step === 8" x-cloak>
      <h2 class="text-xl font-semibold">Modules</h2>
      <p class="mt-1 text-sm text-muted">Untick modules you don't need. You can change this later in Settings → System.</p>
      <div class="mt-4 grid gap-2 sm:grid-cols-3"><template x-for="(label, key) in modules" :key="key"><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" :checked="!disabled.includes(key)" @change="$event.target.checked ? disabled.splice(disabled.indexOf(key), 1) : disabled.push(key)"><span x-text="label"></span></label></template></div>
      <label class="mt-5 flex items-center gap-2 rounded-lg bg-surface-2 p-3 text-sm"><input type="checkbox" class="checkbox" x-model="demo">Add sample data (customers, deals, a project, tickets, invoices) so you can explore. You can delete it later.</label>
    </section>

    <section x-show="step === 9" x-cloak class="py-6 text-center">
      <h2 class="text-xl font-semibold">Installing…</h2>
      <p class="mt-2 text-sm text-muted">Creating tables, roles, your account and company settings.</p>
      <div class="mx-auto mt-6 max-w-sm"><span class="progress block h-2 w-full"><span class="transition-all duration-300" :style="'width:' + progress + '%'"></span></span></div>
    </section>

    <section x-show="step === 10" x-cloak class="py-6 text-center">
      <span class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400"><?= icon('check', 'size-7') ?></span>
      <h2 class="mt-5 text-xl font-semibold">You're all set</h2>
      <p class="mx-auto mt-2 max-w-md text-sm text-muted">The installer is now locked. Next: add the cron job (Settings → System), configure e-mail, and enable two-factor authentication for your account.</p>
      <a :href="redirect" class="btn btn-primary mt-6">Sign in</a>
    </section>

    <div class="mt-8 flex items-center justify-between border-t border-theme pt-5" x-show="step < 9">
      <button type="button" class="btn btn-ghost" @click="back()" x-show="step > 0">Back</button><span x-show="step === 0"></span>
      <button type="button" class="btn btn-primary" @click="next()" :disabled="testing || (step === 1 && !reqOk)" x-text="step === 3 ? 'Test connection' : (step === 8 ? 'Install now' : 'Continue')"></button>
    </div>
  </div>
  <p class="mt-6 text-center text-xs text-faint">After installation this wizard is disabled and config/config.php is protected.</p>
</div>
</body></html>

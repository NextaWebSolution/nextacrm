<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<?php $cronUrl = full_url('/cron.php?key=' . $cronKey); $cronOk = $cronLast > time() - 900; ?>
<section class="card">
  <div class="card-header"><h2 class="card-title">Version &amp; updates</h2><span class="text-xs text-muted">NextaCRM <?= e(App\Core\App::version()) ?> · PHP <?= e(PHP_VERSION) ?> · <?= e($dbVersion) ?></span></div>
  <div class="p-5" x-data="{ res: null, busy: false }">
    <div class="flex flex-wrap gap-2">
      <button type="button" class="btn btn-secondary" :disabled="busy" @click="busy = true; NX.api('<?= e(url('/settings/system/update-check')) ?>', { method: 'POST' }).then(d => res = d).catch(NX.fail).finally(() => busy = false)"><?= icon('refresh-cw') ?>Check for updates</button>
      <template x-if="res && res.available"><button type="button" class="btn btn-primary" data-action="<?= e(url('/settings/system/update')) ?>" data-confirm="Install the update now? A full backup is taken first and the site enters maintenance mode for a minute."><?= icon('download') ?>Install <span x-text="res.info.version"></span></button></template>
    </div>
    <template x-if="res"><div class="mt-3 text-sm" :class="res.ok ? '' : 'text-red-600'"><p x-text="res.message"></p><p class="mt-1 whitespace-pre-line text-xs text-muted" x-show="res.info && res.info.notes" x-text="res.info && res.info.notes"></p></div></template>
    <?php if ($lastCheck): ?><p class="mt-2 text-xs text-faint">Last checked <?= e(time_ago(gmdate('Y-m-d H:i:s', (int) $lastCheck['at']))) ?>.</p><?php endif; ?>
    <details class="mt-4 text-sm"><summary class="cursor-pointer text-muted">Offline update (upload a signed package)</summary>
      <form method="post" action="<?= e(url('/settings/system/update-upload')) ?>" enctype="multipart/form-data" class="mt-3 grid gap-3 sm:grid-cols-3" data-confirm="Install this package? A backup is taken first."><?= csrf_field() ?>
        <div><label class="label">Package (.zip)</label><input type="file" name="package" accept=".zip" class="input py-1.5" required></div><div><label class="label">Version</label><input name="version" class="input" required></div><div><label class="label">Signature (from latest.json)</label><input name="signature" class="input font-mono text-xs" required></div>
        <div class="sm:col-span-3"><button class="btn btn-secondary" type="submit">Verify &amp; install</button></div></form></details>
  </div>
</section>

<section class="card">
  <div class="card-header"><h2 class="card-title">Scheduler (cron)</h2><?= $cronOk ? badge('Running', 'green') : badge($cronLast ? 'Stale' : 'Not configured', 'amber') ?></div>
  <div class="space-y-3 p-5 text-sm">
    <p class="text-muted">Sends e-mails and webhooks, creates recurring invoices, marks overdue invoices, runs reminders, SLA checks and scheduled automations. In cPanel → <b>Cron Jobs</b>, add (every 5 minutes):</p>
    <div class="flex gap-2"><input id="cronline" class="input font-mono text-xs" readonly value="*/5 * * * * php <?= e(NX_ROOT) ?>/cron.php >/dev/null 2>&amp;1"><button type="button" class="btn btn-secondary" data-copy="#cronline"><?= icon('copy') ?></button></div>
    <p class="text-muted">If your host has no CLI cron, call this secret URL every 5 minutes (e.g. with an uptime monitor):</p>
    <div class="flex gap-2"><input id="cronurl" class="input font-mono text-xs" readonly value="<?= e($cronUrl) ?>"><button type="button" class="btn btn-secondary" data-copy="#cronurl"><?= icon('copy') ?></button><form method="post" action="<?= e(url('/settings/system/cron-key')) ?>" data-confirm="Rotate the secret? The old URL stops working."><?= csrf_field() ?><button class="btn btn-ghost" type="submit" title="Rotate key"><?= icon('rotate-ccw') ?></button></form></div>
    <div class="flex flex-wrap items-center gap-3"><button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/system/cron')) ?>"><?= icon('play') ?>Run now</button><span class="text-xs text-muted">Last run: <?= $cronLast ? e(time_ago(gmdate('Y-m-d H:i:s', $cronLast))) : 'never' ?> · <?= $pendingEmails ?> e-mails queued<?= $failedEmails ? ' · ' . $failedEmails . ' failed' : '' ?></span></div>
  </div>
</section>

<form method="post" action="<?= e(url('/settings/system/modules')) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">Modules</h2><span class="text-xs text-faint">Turn off what your business doesn't use</span></div>
  <div class="grid gap-2 p-5 sm:grid-cols-3"><?php foreach ($modules as $k => $l): ?><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="enabled[]" value="<?= e($k) ?>" <?= in_array($k, $disabled, true) ? '' : 'checked' ?>><?= e($l) ?></label><?php endforeach; ?></div>
  <div class="flex justify-end border-t border-theme px-5 py-3"><button class="btn btn-primary" type="submit">Save modules</button></div>
</form>

<section class="card">
  <div class="card-header"><h2 class="card-title">Backups</h2><form method="post" action="<?= e(url('/settings/system/backup')) ?>"><?= csrf_field() ?><input type="hidden" name="include_code" value="1"><button class="btn btn-secondary btn-sm" type="submit"><?= icon('archive') ?>Create backup</button></form></div>
  <?php if (!$backups): ?><p class="px-4 py-6 text-center text-sm text-muted">No backups yet. A backup is also taken automatically before every update.</p><?php else: ?>
  <ul class="divide-theme"><?php foreach ($backups as $b): ?><li class="flex items-center gap-3 px-4 py-2.5 text-sm"><span class="text-faint"><?= icon('archive') ?></span><span class="flex-1 truncate"><?= e($b['name']) ?></span><span class="text-xs text-muted"><?= e(App\Services\FileService::humanSize((int) $b['size'])) ?> · <?= e(time_ago(gmdate('Y-m-d H:i:s', $b['time']))) ?></span><a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/system/backup/' . rawurlencode($b['name']))) ?>"><?= icon('download') ?></a></li><?php endforeach; ?></ul><?php endif; ?>
  <p class="border-t border-theme px-4 py-2.5 text-xs text-faint">Backups contain the database (SQL) and application code. Files in cloud storage are not included. Keep copies off-site.</p>
</section>

<section class="card"><div class="card-header"><h2 class="card-title">Server health</h2></div>
  <ul class="grid gap-x-6 p-5 text-sm sm:grid-cols-2"><?php foreach ($checks as $c): ?><li class="flex items-center gap-2 py-1"><span class="<?= $c['ok'] ? 'text-emerald-600' : ($c['required'] ? 'text-red-600' : 'text-amber-500') ?>"><?= icon($c['ok'] ? 'circle-check' : 'circle-x', 'size-4') ?></span><span class="flex-1 truncate"><?= e($c['label']) ?></span><span class="text-xs text-muted"><?= e($c['value']) ?></span></li><?php endforeach; ?></ul>
</section>
</div></div>

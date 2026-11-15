<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<form method="post" action="<?= e(url('/settings/security')) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">Security policy</h2></div>
  <div class="grid gap-4 p-5 sm:grid-cols-3">
    <div><label class="label">Minimum password length</label><input type="number" min="8" max="64" name="password_min" class="input" value="<?= (int) setting('security.password_min', 10) ?>"></div>
    <div><label class="label">Idle timeout (minutes)</label><input type="number" min="5" name="idle_timeout" class="input" value="<?= (int) setting('security.idle_timeout', 120) ?>"></div>
    <div><label class="label">Permanently erase deleted records after (days)</label><input type="number" min="7" max="3650" name="purge_days" class="input" value="<?= (int) setting('data.purge_days', 90) ?>"><p class="mt-1 text-xs text-muted">Deleted records, their timeline and files are purged by the scheduler.</p></div>
    <div><label class="label">Max session length (minutes)</label><input type="number" min="60" name="session_lifetime" class="input" value="<?= (int) setting('security.session_lifetime', 720) ?>"></div>
    <label class="flex items-center gap-2 text-sm sm:col-span-3"><input type="checkbox" class="checkbox" name="enforce_2fa_admins" value="1" <?= setting('security.enforce_2fa_admins') ? 'checked' : '' ?>>Require two-factor authentication for administrators</label>
    <div><label class="label">Max upload size (MB)</label><input type="number" min="1" max="512" name="max_upload_mb" class="input" value="<?= (int) setting('security.max_upload_mb', 25) ?>"><p class="help">Server limit: <?= e((string) ini_get('upload_max_filesize')) ?></p></div>
    <div class="sm:col-span-2"><label class="label">Allowed file extensions</label><input name="allowed_upload_ext" class="input" value="<?= e(setting('security.allowed_upload_ext')) ?>"><p class="help">Scripts, HTML and SVG are always blocked regardless of this list.</p></div>
  </div>
  <div class="flex justify-end border-t border-theme px-5 py-3"><button class="btn btn-primary" type="submit">Save</button></div>
</form>
<section class="card">
  <div class="card-header"><h2 class="card-title">Built-in protections</h2></div>
  <ul class="grid gap-x-6 gap-y-2 p-5 text-sm sm:grid-cols-2">
    <?php foreach (['Argon2id password hashing', 'Brute-force lockout per IP, account and 2FA code', 'CSRF tokens on every change', 'Strict Content-Security-Policy, HSTS, no framing', 'Session binding, idle & absolute timeouts', 'Tenant & permission checks on every query', 'SSRF-safe webhooks (private networks blocked)', 'Encrypted secrets (SMTP, storage keys, webhooks)', 'Upload type + content validation', 'Signed, checksummed updates', 'Full audit log with field diffs', 'Rate limits on login, forms and API'] as $p): ?><li class="flex items-center gap-2"><span class="text-emerald-600"><?= icon('circle-check', 'size-4') ?></span><?= e($p) ?></li><?php endforeach; ?>
  </ul>
</section>
<section class="card"><div class="card-header"><h2 class="card-title">Recent failed sign-ins</h2></div>
  <?php if (!$failed): ?><p class="px-4 py-6 text-center text-sm text-muted">None.</p><?php else: ?>
  <table class="data-table"><thead><tr><th>E-mail</th><th>IP</th><th>When</th></tr></thead><tbody><?php foreach ($failed as $f): ?><tr><td><?= e($f['email']) ?></td><td><?= e($f['ip']) ?></td><td class="text-muted"><?= e(time_ago($f['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</section>
</div></div>

<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<?php use App\Core\Storage\StorageManager; ?>
<form method="post" action="<?= e(url('/settings/storage')) ?>" class="card" x-data="storageForm(<?= ej(['driver' => $active]) ?>)" x-ref="form">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">File storage</h2><span class="text-xs text-faint">Active: <?= e(StorageManager::DRIVERS[$active]) ?></span></div>
  <div class="p-5">
    <div class="grid gap-2 sm:grid-cols-3">
      <?php foreach (StorageManager::DRIVERS as $k => $l): ?>
        <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 text-sm transition-colors" :class="driver === '<?= $k ?>' ? 'border-primary-400 bg-primary-50/60 dark:bg-primary-900/20' : 'border-theme hover:border-[var(--border-strong)]'">
          <input type="radio" name="driver" value="<?= $k ?>" x-model="driver" class="mt-0.5"><span><span class="block font-medium"><?= e(explode(' (', $l)[0]) ?></span><?php if (str_contains($l, '(')): ?><span class="block text-xs text-muted"><?= e(rtrim(explode(' (', $l)[1], ')')) ?></span><?php endif; ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php foreach (StorageManager::DRIVERS as $k => $l): if ($k === 'local') continue; ?>
      <div x-show="driver === '<?= $k ?>'" x-cloak class="mt-5 grid gap-4 sm:grid-cols-2">
        <?php foreach (StorageManager::fields($k) as $fk => $fl): $v = $config[$k][$fk] ?? ''; $secret = in_array($fk, StorageManager::secretFields(), true); ?>
          <?php if (in_array($fk, ['path_style', 'ssl', 'passive'], true)): ?>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="cfg[<?= $k ?>][<?= $fk ?>]" value="1" <?= $v || ($fk === 'passive' && $v === '') ? 'checked' : '' ?>><?= e($fl) ?></label>
          <?php elseif ($fk === 'private_key'): ?>
            <div class="sm:col-span-2"><label class="label"><?= e($fl) ?></label><textarea name="cfg[<?= $k ?>][<?= $fk ?>]" rows="3" class="textarea font-mono text-xs" placeholder="<?= $v === '__keep__' ? '(saved — leave empty to keep)' : '-----BEGIN OPENSSH PRIVATE KEY-----' ?>"></textarea></div>
          <?php else: ?>
            <div><label class="label"><?= e($fl) ?></label><input name="cfg[<?= $k ?>][<?= $fk ?>]" type="<?= $secret ? 'password' : 'text' ?>" class="input" value="<?= $secret ? '' : e($v) ?>" placeholder="<?= $secret && $v === '__keep__' ? '•••••••• (unchanged)' : '' ?>" autocomplete="off"></div>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($k === 's3c'): ?><p class="help sm:col-span-2">Cloudflare R2: https://&lt;account-id&gt;.r2.cloudflarestorage.com, region “auto”. DigitalOcean Spaces: https://&lt;region&gt;.digitaloceanspaces.com. MinIO: your server URL with path-style on. Backblaze B2: https://s3.&lt;region&gt;.backblazeb2.com.</p><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div x-show="driver === 'local'" class="mt-4 rounded-lg bg-surface-2 p-3 text-sm text-muted">Files are stored in <code>storage/uploads</code> on this server (protected from direct web access) and streamed through permission checks.</div>
    <label class="mt-5 flex items-center gap-2 text-sm" x-show="['s3','wasabi','s3c'].includes(driver)"><input type="checkbox" class="checkbox" name="signed_urls" value="1" <?= setting('storage.signed_urls') ? 'checked' : '' ?>>Serve downloads via 5-minute pre-signed URLs (faster, offloads bandwidth)</label>
    <template x-if="result"><div class="mt-4 rounded-lg border px-3 py-2 text-sm" :class="result.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300'" x-text="result.message"></div></template>
  </div>
  <div class="flex items-center justify-between gap-2 border-t border-theme px-5 py-3">
    <p class="text-xs text-faint">Switching drivers affects new uploads only; existing files stay readable.</p>
    <div class="flex gap-2"><button type="button" class="btn btn-secondary" @click="test()" :disabled="testing"><span x-show="testing" x-cloak><?= icon('loader-circle', 'size-4 animate-spin') ?></span>Test connection</button><button class="btn btn-primary" type="submit">Save &amp; activate</button></div>
  </div>
</form>
<section class="card"><div class="card-header"><h2 class="card-title">Usage by driver</h2></div>
  <ul class="divide-theme text-sm"><?php foreach ($usage as $u): ?><li class="flex justify-between px-4 py-2.5"><span><?= e(StorageManager::DRIVERS[$u['driver']] ?? $u['driver']) ?></span><span class="text-muted"><?= (int) $u['c'] ?> files · <?= e(App\Services\FileService::humanSize((int) $u['s'])) ?></span></li><?php endforeach; ?><?php if (!$usage): ?><li class="px-4 py-6 text-center text-muted">No files yet.</li><?php endif; ?></ul>
</section>
</div></div>

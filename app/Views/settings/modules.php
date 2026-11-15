<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="space-y-5">
  <div class="flex flex-wrap items-end gap-3"><div class="flex-1"><h1 class="text-xl font-semibold tracking-tight">Modules</h1><p class="mt-1 text-sm text-muted">Add features with signed modules from the NextaCRM marketplace or your developer. Modules run with full access to this installation, so only install ones you trust.</p></div>
    <a class="btn btn-secondary" href="<?= e(url('/settings/modules', ['catalog' => 1])) ?>"><?= icon('store') ?>Browse marketplace</a></div>

  <?php if ($dev): ?><div class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200"><?= icon('triangle-alert', 'size-5 shrink-0') ?><div class="flex-1"><strong>Developer mode is on.</strong> Unsigned modules can be installed and enabled. Turn this off on production sites.</div><button class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/modules/developer')) ?>" data-body='{"enabled":0}'>Turn off</button></div><?php endif; ?>

  <section class="card"><div class="card-header"><h2 class="card-title">Installed</h2></div>
    <ul class="divide-theme">
      <?php foreach ($installed as $m): ?>
        <li class="flex flex-wrap items-center gap-3 px-4 py-3">
          <span class="grid size-9 place-items-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300"><?= icon('puzzle', 'size-4') ?></span>
          <div class="min-w-0 flex-1"><p class="font-medium"><?= e($m['name']) ?> <span class="text-xs font-normal text-muted">v<?= e($m['version']) ?><?= $m['author'] ? ' · ' . e($m['author']) : '' ?></span></p>
            <p class="truncate text-xs text-muted"><?= e((string) ($m['manifest']['description'] ?? '')) ?></p></div>
          <?= $m['signed'] ? badge('Verified', 'green') : badge('Unsigned', 'amber') ?>
          <?= $m['enabled'] ? badge('Enabled', 'blue') : badge('Disabled', 'slate') ?>
          <button class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/modules/' . $m['slug'] . '/toggle')) ?>" data-body='<?= e(json_encode(['enabled' => $m['enabled'] ? 0 : 1])) ?>'><?= $m['enabled'] ? 'Disable' : 'Enable' ?></button>
          <button class="btn btn-ghost btn-sm text-red-600" data-action="<?= e(url('/settings/modules/' . $m['slug'])) ?>" data-method="DELETE" data-confirm="Remove <?= e($m['name']) ?>? Its files are deleted; data it created stays unless the module removes it."><?= icon('trash-2') ?></button>
        </li>
      <?php endforeach; ?>
      <?php if (!$installed): ?><li class="px-4 py-8 text-center text-sm text-muted">No modules installed yet.</li><?php endif; ?>
    </ul></section>

  <?php if ($catalog !== null): ?>
  <section class="card"><div class="card-header"><h2 class="card-title">Marketplace</h2></div>
    <?php if (!$catalog['ok']): ?><p class="p-4 text-sm text-muted"><?= e($catalog['message']) ?></p><?php endif; ?>
    <div class="grid gap-3 p-4 sm:grid-cols-2">
      <?php foreach ($catalog['modules'] as $c): ?><div class="rounded-lg border border-theme p-3"><p class="font-medium"><?= e((string) ($c['name'] ?? $c['slug'])) ?> <span class="text-xs text-muted">v<?= e((string) ($c['version'] ?? '')) ?></span></p><p class="mt-1 text-xs text-muted"><?= e((string) ($c['description'] ?? '')) ?></p>
        <button class="btn btn-primary btn-sm mt-3" data-action="<?= e(url('/settings/modules/catalog/' . preg_replace('/[^a-z0-9\-]/', '', (string) $c['slug']))) ?>"><?= icon('download') ?>Install</button></div><?php endforeach; ?>
    </div></section>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/settings/modules/upload')) ?>" enctype="multipart/form-data" class="card"><?= csrf_field() ?>
    <div class="card-header"><h2 class="card-title">Upload a module</h2></div>
    <div class="grid gap-4 p-4 sm:grid-cols-2">
      <div><label class="label" for="package">Module package (.zip, max 10 MB)</label><input id="package" type="file" name="package" accept=".zip,application/zip" class="input" required></div>
      <div><label class="label" for="signature">Signature (from the vendor)</label><input id="signature" name="signature" class="input font-mono text-xs" placeholder="base64 Ed25519 signature"></div>
    </div>
    <div class="flex items-center justify-between gap-3 border-t border-theme p-4"><p class="text-xs text-muted">Only modules signed by Nexta Web Solution are accepted<?= $dev ? ' (Developer mode: unsigned allowed)' : '' ?>. Packages are checked for unsafe paths and file types before anything is extracted.</p><button class="btn btn-primary btn-sm" type="submit"><?= icon('upload') ?>Install</button></div>
  </form>

  <?php if (!$dev): ?>
  <details class="card p-4 text-sm"><summary class="cursor-pointer font-medium">Developer mode</summary>
    <form method="post" action="<?= e(url('/settings/modules/developer')) ?>" data-ajax class="mt-3 space-y-3"><?= csrf_field() ?><input type="hidden" name="enabled" value="1">
      <p class="text-muted">Allows installing modules that are not signed — for building and testing your own modules. Unsigned code is not reviewed and can do anything on this server. Never enable this on a production site you don't fully control.</p>
      <div><label class="label" for="confirm">Type <code>I understand</code> to continue</label><input id="confirm" name="confirm" class="input w-64" autocomplete="off"></div>
      <button class="btn btn-danger btn-sm" type="submit">Turn on Developer mode</button></form></details>
  <?php endif; ?>
</div></div>

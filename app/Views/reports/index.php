<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto max-w-6xl">
  <div class="flex flex-wrap items-center gap-3"><div class="flex-1"><h1 class="text-xl font-semibold tracking-tight">Reports</h1>
  <p class="mt-1 text-sm text-muted">Every report respects your permissions and can be exported to CSV.</p></div>
  <a href="<?= e(url('/reports/builder/new')) ?>" class="btn btn-primary"><?= icon('chart-bar-big') ?>Build a report</a></div>
  <?php $custom = App\Http\Controllers\ReportBuilderController::listFor(); if ($custom): ?>
  <h2 class="mt-6 text-sm font-semibold text-muted">Custom reports</h2>
  <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($custom as $c): ?>
      <a href="<?= e(url('/reports/custom/' . $c['id'])) ?>" class="card group flex items-center gap-3.5 p-4 hover:border-[var(--border-strong)]">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300"><?= icon('file-chart-column', 'size-5') ?></span>
        <span class="min-w-0"><span class="block truncate font-medium group-hover:underline"><?= e($c['name']) ?></span><span class="mt-0.5 block text-xs text-muted"><?= e(App\Services\EntityRegistry::all()[$c['entity']]['label'] ?? $c['entity']) ?><?= $c['schedule'] !== 'none' ? ' · e-mailed ' . e($c['schedule']) : '' ?><?= $c['is_shared'] ? ' · shared' : '' ?></span></span>
      </a>
    <?php endforeach; ?>
  </div>
  <h2 class="mt-6 text-sm font-semibold text-muted">Standard reports</h2>
  <?php endif; ?>
  <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($catalog as $k => $c): ?>
      <a href="<?= e(url('/reports/' . $k)) ?>" class="card group flex gap-3.5 p-4 transition-colors hover:border-[var(--border-strong)]">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300"><?= icon($c['icon'], 'size-5') ?></span>
        <span><span class="block font-medium group-hover:underline"><?= e($c['title']) ?></span><span class="mt-0.5 block text-sm text-muted"><?= e($c['desc']) ?></span></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

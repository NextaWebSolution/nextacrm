<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto max-w-6xl">
  <h1 class="text-xl font-semibold tracking-tight">Reports</h1>
  <p class="mt-1 text-sm text-muted">Every report respects your permissions and can be exported to CSV.</p>
  <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($catalog as $k => $c): ?>
      <a href="<?= e(url('/reports/' . $k)) ?>" class="card group flex gap-3.5 p-4 transition-colors hover:border-[var(--border-strong)]">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300"><?= icon($c['icon'], 'size-5') ?></span>
        <span><span class="block font-medium group-hover:underline"><?= e($c['title']) ?></span><span class="mt-0.5 block text-sm text-muted"><?= e($c['desc']) ?></span></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

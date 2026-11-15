<?php defined('NX_ROOT') || exit; ?>
<h1 class="mb-4 text-xl font-semibold tracking-tight">Projects</h1>
<div class="grid gap-3 sm:grid-cols-2">
  <?php foreach ($projects as $p): ?>
    <a href="<?= e(url('/portal/projects/' . $p['id'])) ?>" class="card block p-4 hover:border-primary-300">
      <div class="flex items-center gap-2"><span class="min-w-0 flex-1 truncate font-medium"><?= e($p['name']) ?></span><?= badge($p['status']) ?></div>
      <div class="mt-3 flex items-center gap-2"><span class="progress flex-1"><span style="width:<?= (int) $p['progress'] ?>%"></span></span><span class="text-xs text-muted"><?= (int) $p['progress'] ?>%</span></div>
      <p class="mt-2 text-xs text-faint"><?= e(fmt_date($p['start_date'])) ?> → <?= e(fmt_date($p['due_date'])) ?></p>
    </a>
  <?php endforeach; ?>
  <?php if (!$projects): ?><p class="card p-8 text-center text-sm text-muted sm:col-span-2">No projects yet.</p><?php endif; ?>
</div>

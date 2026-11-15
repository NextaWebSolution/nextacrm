<?php defined('NX_ROOT') || exit; ?>
<a href="<?= e(url('/portal/projects')) ?>" class="btn btn-ghost btn-sm mb-3"><?= icon('arrow-left') ?>Projects</a>
<div class="card p-5">
  <div class="flex flex-wrap items-center gap-2"><h1 class="flex-1 text-xl font-semibold tracking-tight"><?= e($p['name']) ?></h1><?= badge($p['status']) ?></div>
  <div class="mt-4 grid gap-4 sm:grid-cols-3">
    <div><p class="text-xs text-muted">Progress</p><p class="mt-1 text-lg font-semibold"><?= (int) $p['progress'] ?>%</p><span class="progress mt-1 block"><span style="width:<?= (int) $p['progress'] ?>%"></span></span></div>
    <div><p class="text-xs text-muted">Tasks completed</p><p class="mt-1 text-lg font-semibold"><?= (int) $counts['done'] ?> / <?= (int) $counts['total'] ?></p></div>
    <div><p class="text-xs text-muted">Timeline</p><p class="mt-1 text-sm"><?= e(fmt_date($p['start_date'])) ?> → <?= e(fmt_date($p['due_date'])) ?></p></div>
  </div>
</div>
<section class="card mt-4"><div class="card-header"><h2 class="card-title">Milestones</h2></div>
  <ul class="divide-theme">
    <?php foreach ($milestones as $m): ?><li class="flex items-center gap-3 px-4 py-3"><span class="flex-1"><?= e($m['name']) ?></span><span class="text-xs text-muted"><?= e(fmt_date($m['due_date'])) ?></span><?= badge($m['status']) ?></li><?php endforeach; ?>
    <?php if (!$milestones): ?><li class="px-4 py-6 text-center text-sm text-muted">No milestones yet.</li><?php endif; ?>
  </ul>
</section>

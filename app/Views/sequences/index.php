<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto max-w-5xl">
  <div class="mb-5 flex flex-wrap items-center gap-3">
    <div class="flex-1"><h1 class="text-xl font-semibold tracking-tight">E-mail sequences</h1><p class="mt-1 text-sm text-muted">Automated follow-up e-mails for leads and contacts. They stop when someone replies or unsubscribes.</p></div>
    <?php if (can('sequences', 'create')): ?><a href="<?= e(url('/sequences/new')) ?>" class="btn btn-primary"><?= icon('plus') ?>New sequence</a><?php endif; ?>
  </div>
  <section class="card overflow-x-auto">
    <?php if ($rows): ?>
    <table class="data-table"><thead><tr><th>Name</th><th>For</th><th class="text-right">Steps</th><th class="text-right">Active</th><th class="text-right">Completed</th><th class="text-right">Replied</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($rows as $s): ?><tr class="cursor-pointer" data-href="<?= e(url('/sequences/' . $s['id'])) ?>"><td class="font-medium"><?= e($s['name']) ?></td><td><?= e(App\Services\SequenceService::ENTITIES[$s['entity']] ?? $s['entity']) ?></td><td class="text-right"><?= (int) $s['steps'] ?></td><td class="text-right"><?= (int) $s['active'] ?></td><td class="text-right"><?= (int) $s['completed'] ?></td><td class="text-right"><?= (int) $s['replied'] ?></td><td><?= $s['is_active'] ? badge('Active', 'green') : badge('Paused', 'slate') ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php else: ?><div class="p-10 text-center"><p class="text-sm text-muted">No sequences yet. Create one, then enrol leads from their page or automatically with a workflow.</p></div><?php endif; ?>
  </section>
</div>

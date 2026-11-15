<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<section class="card">
  <div class="card-header"><h2 class="card-title">Automations</h2><a href="<?= e(url('/settings/workflows/new')) ?>" class="btn btn-primary btn-sm"><?= icon('plus') ?>New automation</a></div>
  <?php if (!$flows): ?>
    <div class="px-6 py-12 text-center"><span class="text-faint"><?= icon('workflow', 'mx-auto size-8') ?></span><p class="mt-3 font-medium">No automations yet</p><p class="mx-auto mt-1 max-w-md text-sm text-muted">Examples: assign new website leads round-robin, create a follow-up task when a deal reaches Proposal, e-mail the customer when a ticket is resolved, notify the owner 30 days before a contract ends.</p></div>
  <?php else: ?>
  <ul class="divide-theme">
    <?php foreach ($flows as $w): ?>
      <li class="flex flex-wrap items-center gap-3 px-4 py-3">
        <span class="grid size-9 place-items-center rounded-lg <?= $w['is_active'] ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300' : 'bg-surface-2 text-faint' ?>"><?= icon('zap') ?></span>
        <div class="min-w-0 flex-1"><a href="<?= e(url('/settings/workflows/' . $w['id'])) ?>" class="font-medium hover:underline"><?= e($w['name']) ?></a><p class="text-xs text-muted"><?= e(App\Services\EntityRegistry::all()[$w['entity']]['label'] ?? $w['entity']) ?> · <?= e(App\Services\WorkflowService::TRIGGERS[$w['trigger_type']] ?? $w['trigger_type']) ?> · ran <?= (int) $w['run_count'] ?>×<?= $w['last_run_at'] ? ', last ' . e(time_ago($w['last_run_at'])) : '' ?></p></div>
        <?= $w['last_status'] ? badge(ucfirst($w['last_status']), $w['last_status'] === 'success' ? 'green' : ($w['last_status'] === 'skipped' ? 'slate' : 'red')) : '' ?>
        <button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/workflows/' . $w['id'] . '/toggle')) ?>"><?= $w['is_active'] ? 'Pause' : 'Activate' ?></button>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if ($runs): ?>
<section class="card"><div class="card-header"><h2 class="card-title">Run log</h2></div>
  <ul class="divide-theme text-[13px]"><?php foreach ($runs as $r): $log = json_decode((string) $r['log'], true) ?: []; ?><li class="flex flex-wrap gap-x-3 px-4 py-2"><span class="w-28 shrink-0 text-xs text-muted"><?= e(time_ago($r['created_at'])) ?></span><span class="font-medium"><?= e($r['name']) ?></span><?= badge(ucfirst($r['status']), $r['status'] === 'success' ? 'green' : ($r['status'] === 'skipped' ? 'slate' : 'red')) ?><?php if ($r['record_id'] && $r['entity']): ?><a class="link text-xs" href="<?= e(url('/r/' . $r['entity'] . '/' . $r['record_id'])) ?>"><?= e($r['entity'] . ' #' . $r['record_id']) ?></a><?php endif; ?><span class="w-full truncate text-xs text-muted"><?= e(implode(' · ', $log)) ?></span></li><?php endforeach; ?></ul></section>
<?php endif; ?>
</div></div>

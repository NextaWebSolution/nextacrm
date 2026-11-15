<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto max-w-4xl">
  <h1 class="text-xl font-semibold tracking-tight">Approvals</h1>
  <p class="mt-1 text-sm text-muted">Quotations and expenses waiting for your decision.</p>
  <section class="card mt-5">
    <ul class="divide-theme">
      <?php foreach ($items as $it): ?>
        <li class="flex flex-wrap items-center gap-3 px-4 py-3">
          <span class="grid size-9 place-items-center rounded-lg border border-theme text-muted"><?= icon($it['entity'] === 'quotes' ? 'file-text' : 'wallet') ?></span>
          <div class="min-w-0 flex-1"><a class="font-medium link" href="<?= e(url('/r/' . $it['entity'] . '/' . $it['record_id'])) ?>"><?= e($it['title']) ?></a><p class="text-xs text-muted"><?= e($it['flow_name']) ?> · step <?= (int) $it['current_step'] + 1 ?> · requested by <?= e($it['requester']) ?> <?= e(time_ago($it['created_at'])) ?></p></div>
          <span class="font-medium tabular-nums"><?= e(money((float) $it['amount'], $it['currency'] ?: null)) ?></span>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('/r/' . $it['entity'] . '/' . $it['record_id'])) ?>">Review</a>
        </li>
      <?php endforeach; ?>
      <?php if (!$items): ?><li class="px-4 py-10 text-center text-sm text-muted"><?= icon('circle-check', 'mx-auto mb-2 size-6 text-emerald-500') ?>Nothing waiting for you.</li><?php endif; ?>
    </ul>
  </section>
</div>

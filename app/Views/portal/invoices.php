<?php defined('NX_ROOT') || exit; ?>
<h1 class="mb-4 text-xl font-semibold tracking-tight">Invoices</h1>
<section class="card overflow-x-auto">
  <?php if (!$invoices): ?><p class="p-8 text-center text-sm text-muted">No invoices yet.</p><?php else: ?>
  <table class="data-table"><thead><tr><th>Number</th><th>Date</th><th>Due</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Balance</th></tr></thead><tbody>
    <?php foreach ($invoices as $i): ?><tr class="cursor-pointer" data-href="<?= e(url('/portal/invoices/' . $i['id'])) ?>"><td class="font-medium"><?= e($i['number']) ?></td><td><?= e(fmt_date($i['issue_date'])) ?></td><td><?= e(fmt_date($i['due_date'])) ?></td><td><?= badge($i['status']) ?></td><td class="text-right"><?= e(money((float) $i['total'], $i['currency'])) ?></td><td class="text-right font-medium"><?= e(money((float) $i['balance'], $i['currency'])) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>

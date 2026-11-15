<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto max-w-3xl">
  <h1 class="text-xl font-semibold tracking-tight">My payslips</h1>
  <?php if (!$me): ?><p class="card mt-5 p-8 text-center text-sm text-muted">Your user isn't linked to an employee record yet.</p><?php else: ?>
  <section class="card mt-5"><ul class="divide-theme">
    <?php foreach ($slips as $s): ?><li class="flex items-center gap-3 px-4 py-3"><span class="flex-1 font-medium"><?= e(date('F Y', strtotime($s['month'] . '-01'))) ?></span><span class="text-sm tabular-nums"><?= e(money((float) $s['net'])) ?> net</span><?= badge($s['status'], $s['status'] === 'Paid' ? 'green' : 'blue') ?><a class="btn btn-secondary btn-sm" href="<?= e(url('/payroll/payslips/' . $s['id'] . '/pdf')) ?>"><?= icon('download') ?>PDF</a></li><?php endforeach; ?>
    <?php if (!$slips): ?><li class="px-4 py-8 text-center text-sm text-muted">No payslips yet.</li><?php endif; ?>
  </ul></section><?php endif; ?>
</div>

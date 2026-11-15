<?php defined('NX_ROOT') || exit; ?>
<h1 class="mb-4 text-xl font-semibold tracking-tight">Quotations</h1>
<section class="card overflow-x-auto">
  <?php if (!$quotes): ?><p class="p-8 text-center text-sm text-muted">No quotations yet.</p><?php else: ?>
  <table class="data-table"><thead><tr><th>Number</th><th>Subject</th><th>Valid until</th><th>Status</th><th class="text-right">Total</th><th></th></tr></thead><tbody>
    <?php foreach ($quotes as $q): ?><tr><td class="font-medium"><?= e($q['number']) ?></td><td class="max-w-xs truncate"><?= e($q['subject'] ?? '') ?></td><td><?= e(fmt_date($q['valid_until'])) ?></td><td><?= badge($q['status']) ?></td><td class="text-right"><?= e(money((float) $q['total'], $q['currency'])) ?></td>
      <td class="text-right"><?php if (!empty($q['public_token'])): ?><a class="btn btn-<?= $q['status'] === 'Sent' ? 'primary' : 'secondary' ?> btn-sm" href="<?= e(url('/p/quote/' . $q['public_token'])) ?>"><?= $q['status'] === 'Sent' ? 'Review & sign' : 'View' ?></a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>

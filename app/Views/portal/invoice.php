<?php defined('NX_ROOT') || exit; $payable = in_array($inv['status'], App\Services\Payments\PaymentService::PAYABLE, true) && (float) $inv['balance'] > 0; ?>
<div class="mb-4 flex flex-wrap items-center gap-3">
  <a href="<?= e(url('/portal/invoices')) ?>" class="btn btn-ghost btn-sm"><?= icon('arrow-left') ?>Invoices</a>
  <h1 class="flex-1 text-xl font-semibold tracking-tight"><?= e($inv['number']) ?></h1>
  <a href="<?= e(url('/portal/invoices/' . $inv['id'] . '/pdf')) ?>" class="btn btn-secondary"><?= icon('download') ?>PDF</a>
</div>
<?php if ($payable && $gateways): ?>
<section class="card mb-4 p-4">
  <div class="flex flex-wrap items-center gap-3">
    <div class="min-w-0 flex-1"><p class="text-sm text-muted">Balance due</p><p class="text-2xl font-semibold"><?= e(money((float) $inv['balance'], $inv['currency'])) ?></p></div>
    <?php foreach ($gateways as $g): ?>
      <form method="post" action="<?= e(url('/portal/invoices/' . $inv['id'] . '/pay')) ?>"><?= csrf_field() ?><input type="hidden" name="gateway" value="<?= e($g->key()) ?>"><button class="btn btn-primary" type="submit"><?= icon('credit-card') ?>Pay with <?= e($g->label()) ?></button></form>
    <?php endforeach; ?>
  </div>
  <p class="mt-2 flex items-center gap-1.5 text-xs text-faint"><?= icon('lock', 'size-3.5') ?>You'll complete the payment on the provider's secure page.</p>
</section>
<?php endif; ?>
<?= App\Core\View::partial('documents/_preview', $doc + ['compact' => false]) ?>

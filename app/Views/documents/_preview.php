<?php defined('NX_ROOT') || exit;
/** @var string $type @var array $doc @var array $items @var array $company @var ?array $customer @var ?array $contact @var ?string $logo @var array $payments @var array $taxSummary */
$cur = $doc['currency'];
$isQuote = $type === 'quote';
?>
<div class="doc-paper overflow-hidden text-[13px]">
  <div class="h-1.5" style="background: <?= e($company['brand_color'] ?? '#4f46e5') ?>"></div>
  <div class="p-6 sm:p-9">
    <div class="flex flex-wrap items-start justify-between gap-6">
      <div class="min-w-0">
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" class="mb-3 max-h-14 max-w-48 object-contain"><?php endif; ?>
        <p class="text-base font-semibold"><?= e($company['legal_name'] ?: $company['name']) ?></p>
        <p class="mt-1 whitespace-pre-line text-xs leading-relaxed text-zinc-500"><?= e(trim(implode("\n", array_filter([$company['address'] ?? '', trim(($company['city'] ?? '') . ' ' . ($company['postal_code'] ?? '')), $company['country'] ?? '', $company['tax_id'] ? 'Tax ID: ' . $company['tax_id'] : '', $company['email'] ?? '', $company['phone'] ?? ''])))) ?></p>
      </div>
      <div class="text-right">
        <p class="text-2xl font-semibold tracking-tight text-zinc-900"><?= $isQuote ? 'QUOTATION' : 'INVOICE' ?></p>
        <p class="mt-1 font-medium text-zinc-600"><?= e($doc['number']) ?></p>
        <?php if (!$isQuote): ?><p class="mt-3 text-xs text-zinc-500">Balance due</p><p class="text-lg font-semibold text-zinc-900"><?= e(money($doc['balance'], $cur)) ?></p><?php endif; ?>
      </div>
    </div>
    <div class="mt-8 grid gap-6 sm:grid-cols-2">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-400"><?= $isQuote ? 'Prepared for' : 'Bill to' ?></p>
        <p class="mt-1.5 font-semibold text-zinc-900"><?= e($customer['name'] ?? '—') ?></p>
        <?php if ($contact): ?><p class="text-zinc-600">Attn: <?= e($contact['name']) ?></p><?php endif; ?>
        <p class="whitespace-pre-line text-xs leading-relaxed text-zinc-500"><?= e(trim(implode("\n", array_filter([$customer['billing_address'] ?? '', trim(($customer['city'] ?? '') . ' ' . ($customer['postal_code'] ?? '')), $customer['country'] ?? '', !empty($customer['tax_id']) ? 'Tax ID: ' . $customer['tax_id'] : ''])))) ?></p>
      </div>
      <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:justify-self-end">
        <dt class="text-zinc-500"><?= $isQuote ? 'Date' : 'Invoice date' ?></dt><dd class="text-right font-medium text-zinc-900"><?= e(fmt_date($doc['issue_date'])) ?></dd>
        <?php if ($isQuote): ?><dt class="text-zinc-500">Valid until</dt><dd class="text-right font-medium text-zinc-900"><?= e(fmt_date($doc['valid_until'] ?? null)) ?: '—' ?></dd>
        <?php else: ?><dt class="text-zinc-500">Due date</dt><dd class="text-right font-medium text-zinc-900"><?= e(fmt_date($doc['due_date'] ?? null)) ?></dd><?php endif; ?>
        <?php if ($doc['subject']): ?><dt class="text-zinc-500">Subject</dt><dd class="text-right font-medium text-zinc-900"><?= e($doc['subject']) ?></dd><?php endif; ?>
        <dt class="text-zinc-500">Status</dt><dd class="text-right"><?= badge($doc['status']) ?></dd>
      </dl>
    </div>
    <div class="mt-8 overflow-x-auto">
      <table class="w-full min-w-[34rem] text-left">
        <thead><tr class="border-b-2 border-zinc-200 text-[11px] uppercase tracking-wider text-zinc-500"><th class="py-2 pr-2 font-semibold">#</th><th class="py-2 font-semibold">Description</th><th class="py-2 text-right font-semibold">Qty</th><th class="py-2 text-right font-semibold">Rate</th><th class="py-2 text-right font-semibold">Tax</th><th class="py-2 text-right font-semibold">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($items as $i => $it): $lines = explode("\n", (string) $it['description'], 2); ?>
            <tr class="border-b border-zinc-100 align-top">
              <td class="py-2.5 pr-2 text-zinc-400"><?= $i + 1 ?></td>
              <td class="py-2.5 pr-4"><p class="font-medium text-zinc-900"><?= e($lines[0]) ?></p><?php if (isset($lines[1])): ?><p class="whitespace-pre-line text-xs text-zinc-500"><?= e($lines[1]) ?></p><?php endif; ?><?php if ((float) $it['discount_percent'] > 0): ?><p class="text-xs text-emerald-600"><?= (float) $it['discount_percent'] ?>% discount</p><?php endif; ?></td>
              <td class="py-2.5 text-right tabular-nums text-zinc-700"><?= e(rtrim(rtrim(number_format((float) $it['quantity'], 3), '0'), '.')) ?> <span class="text-xs text-zinc-400"><?= e($it['unit']) ?></span></td>
              <td class="py-2.5 text-right tabular-nums text-zinc-700"><?= e(money($it['unit_price'], $cur)) ?></td>
              <td class="py-2.5 text-right tabular-nums text-zinc-500"><?= (float) $it['tax_rate'] > 0 ? e(rtrim(rtrim((string) $it['tax_rate'], '0'), '.')) . '%' : '—' ?></td>
              <td class="py-2.5 text-right font-medium tabular-nums text-zinc-900"><?= e(money($it['line_total'], $cur)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-6 flex justify-end">
      <dl class="w-full max-w-xs space-y-1.5 text-[13px]">
        <div class="flex justify-between text-zinc-600"><dt>Subtotal</dt><dd class="tabular-nums"><?= e(money($doc['subtotal'], $cur)) ?></dd></div>
        <?php if ((float) $doc['discount_total'] > 0): ?><div class="flex justify-between text-emerald-700"><dt>Discount<?= $doc['discount_type'] === 'percent' ? ' (' . (float) $doc['discount_value'] . '%)' : '' ?></dt><dd class="tabular-nums">−<?= e(money($doc['discount_total'], $cur)) ?></dd></div><?php endif; ?>
        <?php foreach ($taxSummary as $rate => $amt): ?><div class="flex justify-between text-zinc-600"><dt>Tax <?= e($rate) ?>%</dt><dd class="tabular-nums"><?= e(money($amt * ((float) $doc['subtotal'] > 0 ? ((float) $doc['subtotal'] - (float) $doc['discount_total']) / (float) $doc['subtotal'] : 1), $cur)) ?></dd></div><?php endforeach; ?>
        <?php if ((float) $doc['adjustment'] != 0.0): ?><div class="flex justify-between text-zinc-600"><dt>Adjustment</dt><dd class="tabular-nums"><?= e(money($doc['adjustment'], $cur)) ?></dd></div><?php endif; ?>
        <div class="flex justify-between border-t border-zinc-200 pt-2 text-base font-semibold text-zinc-900"><dt>Total</dt><dd class="tabular-nums"><?= e(money($doc['total'], $cur)) ?></dd></div>
        <?php if (!$isQuote && (float) $doc['amount_paid'] > 0): ?>
          <div class="flex justify-between text-zinc-600"><dt>Paid</dt><dd class="tabular-nums">−<?= e(money($doc['amount_paid'], $cur)) ?></dd></div>
          <div class="flex justify-between font-semibold text-zinc-900"><dt>Balance due</dt><dd class="tabular-nums"><?= e(money($doc['balance'], $cur)) ?></dd></div>
        <?php endif; ?>
      </dl>
    </div>
    <?php if ($payments): ?>
      <div class="mt-6"><p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Payments received</p>
        <ul class="mt-2 space-y-1 text-xs text-zinc-600"><?php foreach ($payments as $p): ?><li class="flex justify-between gap-4"><span><?= e(fmt_date($p['paid_on'])) ?> · <?= e($p['method'] ?? '') ?> <?= $p['reference'] ? '· ' . e($p['reference']) : '' ?></span><span class="tabular-nums"><?= e(money($p['amount'], $cur)) ?></span></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($doc['notes'] || $doc['terms'] || (!$isQuote && $company['bank_details'])): ?>
    <div class="mt-8 grid gap-6 border-t border-zinc-100 pt-6 text-xs text-zinc-600 sm:grid-cols-2">
      <?php if ($doc['notes']): ?><div><p class="mb-1 font-semibold text-zinc-800">Notes</p><p class="whitespace-pre-line"><?= e($doc['notes']) ?></p></div><?php endif; ?>
      <?php if ($doc['terms']): ?><div><p class="mb-1 font-semibold text-zinc-800">Terms</p><p class="whitespace-pre-line"><?= e($doc['terms']) ?></p></div><?php endif; ?>
      <?php if (!$isQuote && $company['bank_details']): ?><div><p class="mb-1 font-semibold text-zinc-800">Payment details</p><p class="whitespace-pre-line"><?= e($company['bank_details']) ?></p></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($company['invoice_footer']): ?><p class="mt-8 text-center text-xs text-zinc-400"><?= e($company['invoice_footer']) ?></p><?php endif; ?>
  </div>
</div>

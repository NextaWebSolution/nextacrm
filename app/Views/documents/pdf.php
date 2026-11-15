<?php defined('NX_ROOT') || exit;
$cur = $doc['currency'];
$isQuote = $type === 'quote';
$brand = preg_match('/^#[0-9a-f]{6}$/i', (string) ($company['brand_color'] ?? '')) ? $company['brand_color'] : '#4f46e5';
$m = fn ($v) => e(money($v, $cur));
?><!doctype html>
<html><head><meta charset="utf-8"><title><?= e($doc['number']) ?></title>
<style>
  @page { margin: 28px 34px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2937; line-height: 1.45; }
  .bar { height: 5px; background: <?= $brand ?>; margin: -28px -34px 22px; }
  table { width: 100%; border-collapse: collapse; }
  .muted { color: #6b7280; } .faint { color: #9ca3af; } .right { text-align: right; } .b { font-weight: bold; }
  .title { font-size: 22px; font-weight: bold; letter-spacing: 1px; color: #111827; }
  .label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; font-weight: bold; }
  .items th { font-size: 8px; text-transform: uppercase; letter-spacing: .8px; color: #6b7280; border-bottom: 1.5px solid #d1d5db; padding: 6px 4px; text-align: left; }
  .items td { padding: 7px 4px; border-bottom: 1px solid #f0f0f2; vertical-align: top; }
  .totals td { padding: 3px 4px; }
  .grand td { border-top: 1.5px solid #d1d5db; font-size: 12px; font-weight: bold; padding-top: 6px; }
  .pre { white-space: pre-line; }
  .status { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f3f4f6; font-size: 8px; font-weight: bold; text-transform: uppercase; }
</style></head>
<body>
<div class="bar"></div>
<table><tr>
  <td style="width:60%; vertical-align:top">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" style="max-height:48px; max-width:180px; margin-bottom:8px"><br><?php endif; ?>
    <div class="b" style="font-size:12px"><?= e($company['legal_name'] ?: $company['name']) ?></div>
    <div class="muted pre"><?= e(trim(implode("\n", array_filter([$company['address'] ?? '', trim(($company['city'] ?? '') . ' ' . ($company['postal_code'] ?? '')), $company['country'] ?? '', $company['tax_id'] ? 'Tax ID: ' . $company['tax_id'] : '', $company['email'] ?? '', $company['phone'] ?? ''])))) ?></div>
  </td>
  <td class="right" style="vertical-align:top">
    <div class="title"><?= $isQuote ? 'QUOTATION' : 'INVOICE' ?></div>
    <div class="b muted" style="font-size:11px"><?= e($doc['number']) ?></div>
    <div style="margin-top:6px"><span class="status"><?= e($doc['status']) ?></span></div>
  </td>
</tr></table>

<table style="margin-top:22px"><tr>
  <td style="width:55%; vertical-align:top">
    <div class="label"><?= $isQuote ? 'Prepared for' : 'Bill to' ?></div>
    <div class="b" style="font-size:11px; margin-top:3px"><?= e($customer['name'] ?? '—') ?></div>
    <?php if ($contact): ?><div>Attn: <?= e($contact['name']) ?></div><?php endif; ?>
    <div class="muted pre"><?= e(trim(implode("\n", array_filter([$customer['billing_address'] ?? '', trim(($customer['city'] ?? '') . ' ' . ($customer['postal_code'] ?? '')), $customer['country'] ?? '', !empty($customer['tax_id']) ? 'Tax ID: ' . $customer['tax_id'] : ''])))) ?></div>
  </td>
  <td style="vertical-align:top">
    <table>
      <tr><td class="muted"><?= $isQuote ? 'Date' : 'Invoice date' ?></td><td class="right b"><?= e(fmt_date($doc['issue_date'])) ?></td></tr>
      <?php if ($isQuote): ?><tr><td class="muted">Valid until</td><td class="right b"><?= e(fmt_date($doc['valid_until'] ?? null)) ?></td></tr>
      <?php else: ?><tr><td class="muted">Due date</td><td class="right b"><?= e(fmt_date($doc['due_date'] ?? null)) ?></td></tr><tr><td class="muted">Balance due</td><td class="right b"><?= $m($doc['balance']) ?></td></tr><?php endif; ?>
      <?php if ($doc['subject']): ?><tr><td class="muted">Subject</td><td class="right"><?= e($doc['subject']) ?></td></tr><?php endif; ?>
    </table>
  </td>
</tr></table>

<table class="items" style="margin-top:22px">
  <thead><tr><th style="width:4%">#</th><th>Description</th><th class="right" style="width:10%">Qty</th><th class="right" style="width:15%">Rate</th><th class="right" style="width:8%">Tax</th><th class="right" style="width:16%">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i => $it): $lines = explode("\n", (string) $it['description'], 2); ?>
    <tr>
      <td class="faint"><?= $i + 1 ?></td>
      <td><span class="b"><?= e($lines[0]) ?></span><?php if (isset($lines[1])): ?><br><span class="muted pre"><?= e($lines[1]) ?></span><?php endif; ?><?php if ((float) $it['discount_percent'] > 0): ?><br><span style="color:#047857"><?= (float) $it['discount_percent'] ?>% discount</span><?php endif; ?></td>
      <td class="right"><?= e(rtrim(rtrim(number_format((float) $it['quantity'], 3), '0'), '.')) ?> <span class="faint"><?= e($it['unit']) ?></span></td>
      <td class="right"><?= $m($it['unit_price']) ?></td>
      <td class="right muted"><?= (float) $it['tax_rate'] > 0 ? e(rtrim(rtrim((string) $it['tax_rate'], '0'), '.')) . '%' : '—' ?></td>
      <td class="right b"><?= $m($it['line_total']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<table style="margin-top:12px"><tr><td style="width:55%"></td><td>
  <table class="totals">
    <tr><td class="muted">Subtotal</td><td class="right"><?= $m($doc['subtotal']) ?></td></tr>
    <?php if ((float) $doc['discount_total'] > 0): ?><tr><td style="color:#047857">Discount</td><td class="right" style="color:#047857">-<?= $m($doc['discount_total']) ?></td></tr><?php endif; ?>
    <?php foreach ($taxSummary as $rate => $amt): ?><tr><td class="muted">Tax <?= e($rate) ?>%</td><td class="right"><?= $m($amt * ((float) $doc['subtotal'] > 0 ? ((float) $doc['subtotal'] - (float) $doc['discount_total']) / (float) $doc['subtotal'] : 1)) ?></td></tr><?php endforeach; ?>
    <?php if ((float) $doc['adjustment'] != 0.0): ?><tr><td class="muted">Adjustment</td><td class="right"><?= $m($doc['adjustment']) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>Total</td><td class="right"><?= $m($doc['total']) ?></td></tr>
    <?php if (!$isQuote && (float) $doc['amount_paid'] > 0): ?><tr><td class="muted">Paid</td><td class="right">-<?= $m($doc['amount_paid']) ?></td></tr><tr><td class="b">Balance due</td><td class="right b"><?= $m($doc['balance']) ?></td></tr><?php endif; ?>
  </table>
</td></tr></table>

<?php if ($doc['notes']): ?><div style="margin-top:22px"><div class="label">Notes</div><div class="pre"><?= e($doc['notes']) ?></div></div><?php endif; ?>
<?php if ($doc['terms']): ?><div style="margin-top:14px"><div class="label">Terms &amp; conditions</div><div class="pre muted"><?= e($doc['terms']) ?></div></div><?php endif; ?>
<?php if (!$isQuote && !empty($company['bank_details'])): ?><div style="margin-top:14px"><div class="label">Payment details</div><div class="pre"><?= e($company['bank_details']) ?></div></div><?php endif; ?>
<?php if (!empty($company['invoice_footer'])): ?><div class="faint" style="margin-top:28px; text-align:center"><?= e($company['invoice_footer']) ?></div><?php endif; ?>
</body></html>

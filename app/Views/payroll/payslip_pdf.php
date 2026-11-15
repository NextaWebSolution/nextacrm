<?php defined('NX_ROOT') || exit; $m = fn ($v) => number_format((float) $v, 2); $month = date('F Y', strtotime($run['month'] . '-01')); $ded = (float) $s['pf'] + (float) $s['esi'] + (float) $s['pt'] + (float) $s['tds'] + (float) $s['other_deductions']; ?><!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:'DejaVu Sans',sans-serif;font-size:11px;color:#1f2937;margin:32px}h1{font-size:16px;margin:0}.muted{color:#6b7280}table{width:100%;border-collapse:collapse}
.info td{padding:4px 0}.grid td{padding:6px 8px;border:1px solid #e5e7eb}.grid th{padding:6px 8px;background:#f3f4f6;border:1px solid #e5e7eb;text-align:left}.r{text-align:right}.net{margin-top:14px;padding:10px;background:#eef2ff;font-size:13px}
</style></head><body>
<table><tr><td><h1><?= e($company['name'] ?? '') ?></h1><div class="muted"><?= e(str_replace("\n", ', ', (string) ($company['address'] ?? ''))) ?></div></td><td class="r"><strong>Payslip</strong><div class="muted"><?= e($month) ?></div></td></tr></table>
<table class="info" style="margin-top:16px"><tr><td class="muted">Employee</td><td><?= e($s['name']) ?></td><td class="muted">Employee code</td><td><?= e((string) $s['employee_code']) ?></td></tr>
<tr><td class="muted">Designation</td><td><?= e((string) $s['designation']) ?></td><td class="muted">PAN / UAN</td><td><?= e(($s['pan'] ?: '—') . ' / ' . ($s['uan'] ?: '—')) ?></td></tr>
<tr><td class="muted">Paid days</td><td><?= (float) $s['paid_days'] ?> of <?= (int) $s['working_days'] ?></td><td class="muted">Loss of pay days</td><td><?= (float) $s['lop_days'] ?></td></tr></table>
<table class="grid" style="margin-top:14px"><tr><th>Earnings</th><th class="r">Amount</th><th>Deductions</th><th class="r">Amount</th></tr>
<tr><td>Basic</td><td class="r"><?= $m($s['basic']) ?></td><td>Provident fund</td><td class="r"><?= $m($s['pf']) ?></td></tr>
<tr><td>House rent allowance</td><td class="r"><?= $m($s['hra']) ?></td><td>ESI</td><td class="r"><?= $m($s['esi']) ?></td></tr>
<tr><td>Special allowance</td><td class="r"><?= $m($s['special']) ?></td><td>Professional tax</td><td class="r"><?= $m($s['pt']) ?></td></tr>
<tr><td></td><td></td><td>Income tax (TDS)</td><td class="r"><?= $m($s['tds']) ?></td></tr>
<tr><td></td><td></td><td>Other deductions</td><td class="r"><?= $m($s['other_deductions']) ?></td></tr>
<tr><th>Gross earnings</th><th class="r"><?= $m($s['gross']) ?></th><th>Total deductions</th><th class="r"><?= $m($ded) ?></th></tr></table>
<div class="net"><strong>Net pay: <?= e(money((float) $s['net'], $company['currency'] ?? 'INR')) ?></strong></div>
<p class="muted" style="margin-top:24px">This is a computer-generated payslip and does not need a signature.</p>
</body></html>

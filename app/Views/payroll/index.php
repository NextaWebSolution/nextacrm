<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto max-w-5xl">
  <div class="mb-5 flex flex-wrap items-center gap-3"><h1 class="flex-1 text-xl font-semibold tracking-tight">Payroll</h1><a class="btn btn-secondary" href="<?= e(url('/attendance')) ?>"><?= icon('calendar-check') ?>Attendance</a></div>
  <?php if (can('payroll', 'create')): ?>
  <form method="post" action="<?= e(url('/payroll/generate')) ?>" data-ajax class="card mb-5 flex flex-wrap items-end gap-3 p-4"><?= csrf_field() ?>
    <div><label class="label" for="m">Month</label><input id="m" type="month" name="month" class="input" value="<?= e(date('Y-m', strtotime('first day of last month'))) ?>" max="<?= e(date('Y-m')) ?>" required></div>
    <button class="btn btn-primary" type="submit"><?= icon('banknote') ?>Run payroll</button>
    <p class="text-xs text-muted">Uses each employee's monthly salary, attendance (loss of pay) and the rules below. You can review and adjust TDS before approving.</p>
  </form>
  <?php endif; ?>
  <section class="card overflow-x-auto"><table class="data-table"><thead><tr><th>Month</th><th>Status</th><th class="text-right">Gross</th><th class="text-right">Deductions</th><th class="text-right">Net pay</th></tr></thead><tbody>
    <?php foreach ($runs as $r): ?><tr class="cursor-pointer" data-href="<?= e(url('/payroll/' . $r['id'])) ?>"><td class="font-medium"><?= e(date('F Y', strtotime($r['month'] . '-01'))) ?></td><td><?= badge($r['status'], ['Draft' => 'slate', 'Approved' => 'blue', 'Paid' => 'green'][$r['status']] ?? 'slate') ?></td><td class="text-right tabular-nums"><?= e(money((float) $r['total_gross'])) ?></td><td class="text-right tabular-nums"><?= e(money((float) $r['total_deductions'])) ?></td><td class="text-right font-medium tabular-nums"><?= e(money((float) $r['total_net'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$runs): ?><tr><td colspan="5" class="py-8 text-center text-muted">No payroll runs yet.</td></tr><?php endif; ?></tbody></table></section>
  <?php if (can('payroll', 'manage')): ?>
  <form method="post" action="<?= e(url('/payroll/settings')) ?>" data-ajax class="card mt-5"><?= csrf_field() ?><div class="card-header"><h2 class="card-title">Salary structure & statutory rules</h2></div>
    <div class="grid gap-4 p-4 sm:grid-cols-4">
      <?php foreach (['basic_pct' => 'Basic (% of gross)', 'hra_pct' => 'HRA (% of basic)', 'pf_rate' => 'PF employee (%)', 'pf_cap' => 'PF wage ceiling', 'esi_rate' => 'ESI employee (%)', 'esi_threshold' => 'ESI applies up to gross', 'pt' => 'Professional tax / month'] as $k => $l): ?>
        <div><label class="label" for="pc-<?= $k ?>"><?= e($l) ?></label><input id="pc-<?= $k ?>" type="number" step="any" min="0" name="<?= ['pt' => 'pt_amount', 'pf_cap' => 'pf_wage_cap'][$k] ?? $k ?>" class="input" value="<?= e((string) $cfg[$k]) ?>"></div>
      <?php endforeach; ?>
      <div><label class="label" for="pc-basis">Per-day salary based on</label><select id="pc-basis" name="days_basis" class="select"><option value="calendar">Calendar days</option><option value="working" <?= $cfg['basis'] === 'working' ? 'selected' : '' ?>>Working days</option></select></div>
    </div>
    <div class="flex items-center justify-between gap-3 border-t border-theme p-4"><p class="text-xs text-muted">Defaults follow common Indian payroll practice. Check them against your state's professional-tax slab and your accountant's advice.</p><button class="btn btn-primary btn-sm" type="submit"><?= icon('save') ?>Save</button></div>
  </form>
  <?php endif; ?>
</div>

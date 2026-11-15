<?php defined('NX_ROOT') || exit;
$prev = date('Y-m', strtotime($ym . '-01 -1 month')); $next = date('Y-m', strtotime($ym . '-01 +1 month'));
$colors = ['P' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300', 'WFH' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300', 'HD' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300', 'A' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300', 'L' => 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300', 'U' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300', 'H' => 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300', 'W' => 'text-faint', '–' => 'text-faint'];
$statusFor = ['P' => 'Present', 'HD' => 'Half Day', 'A' => 'Absent', 'L' => 'Leave', 'H' => 'Holiday', 'WFH' => 'Work From Home'];
?>
<div class="mx-auto max-w-[1400px]">
  <div class="mb-5 flex flex-wrap items-center gap-3"><h1 class="flex-1 text-xl font-semibold tracking-tight">Attendance</h1>
    <?php if (can('payroll', 'view')): ?><a class="btn btn-secondary" href="<?= e(url('/payroll')) ?>"><?= icon('banknote') ?>Payroll</a><?php endif; ?>
    <a class="btn btn-secondary" href="<?= e(url('/payroll/me')) ?>"><?= icon('file-text') ?>My payslips</a></div>
  <?php if ($me): ?>
  <section class="card mb-5 flex flex-wrap items-center gap-4 p-4">
    <span class="grid size-11 place-items-center rounded-xl bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300"><?= icon('clock-4', 'size-5') ?></span>
    <div class="min-w-0 flex-1"><p class="font-medium">Today · <?= e(fmt_date(App\Services\AttendanceService::today())) ?></p>
      <p class="text-sm text-muted"><?php if ($today && $today['check_in']): ?>In at <?= e(substr(fmt_datetime($today['check_in']), -5)) ?><?= $today['check_out'] ? ' · out at ' . e(substr(fmt_datetime($today['check_out']), -5)) . ' · ' . intdiv((int) $today['minutes'], 60) . 'h ' . ((int) $today['minutes'] % 60) . 'm' : ' · working' ?><?php else: ?>Not checked in yet.<?php endif; ?></p></div>
    <?php if (!$today || !$today['check_in']): ?><button class="btn btn-primary" data-action="<?= e(url('/attendance/check-in')) ?>"><?= icon('circle-check-big') ?>Check in</button>
    <?php elseif (!$today['check_out']): ?><button class="btn btn-secondary" data-action="<?= e(url('/attendance/check-out')) ?>"><?= icon('log-out') ?>Check out</button><?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($reg): ?>
  <section class="card" x-data="attendanceGrid(<?= ej(['url' => url('/attendance/mark'), 'canMark' => $canMark]) ?>)">
    <div class="card-header"><div class="flex items-center gap-2"><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/attendance', ['month' => $prev])) ?>" aria-label="Previous month"><?= icon('chevron-left') ?></a><h2 class="card-title"><?= e(date('F Y', strtotime($ym . '-01'))) ?></h2><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/attendance', ['month' => $next])) ?>" aria-label="Next month"><?= icon('chevron-right') ?></a></div>
      <p class="hidden gap-2 text-[11px] text-muted md:flex"><?php foreach (['P' => 'Present', 'HD' => 'Half day', 'A' => 'Absent', 'L' => 'Leave', 'U' => 'Unpaid', 'H' => 'Holiday', 'W' => 'Weekly off'] as $k => $l): ?><span class="rounded px-1.5 <?= $colors[$k] ?>"><?= $k ?> <?= $l ?></span><?php endforeach; ?></p></div>
    <div class="overflow-x-auto"><table class="w-full border-collapse text-xs">
      <thead><tr><th class="sticky left-0 z-10 surface px-3 py-2 text-left font-medium">Employee</th>
        <?php foreach ($reg['days'] as $d): $isOff = in_array((int) date('N', strtotime($d)), $reg['off'], true); ?><th class="min-w-8 px-0.5 py-2 text-center font-medium <?= $isOff || isset($reg['holidays'][$d]) ? 'text-faint' : '' ?>" title="<?= e($reg['holidays'][$d] ?? '') ?>"><?= (int) substr($d, 8) ?><span class="block text-[9px] font-normal text-faint"><?= e(substr(date('D', strtotime($d)), 0, 2)) ?></span></th><?php endforeach; ?>
        <th class="px-2 py-2 text-right font-medium">Present</th><th class="px-2 py-2 text-right font-medium">Leave</th><th class="px-2 py-2 text-right font-medium">LOP</th></tr></thead>
      <tbody>
        <?php foreach ($reg['rows'] as $row): $e = $row['employee']; ?>
          <tr class="border-t border-theme"><td class="sticky left-0 z-10 surface whitespace-nowrap px-3 py-1.5"><a class="font-medium hover:underline" href="<?= e(url('/r/employees/' . $e['id'])) ?>"><?= e($e['name']) ?></a></td>
            <?php foreach ($row['cells'] as $d => $c): ?><td class="p-0.5 text-center"><button type="button" class="h-6 w-full rounded text-[10px] font-medium <?= $colors[$c['code']] ?? '' ?>" <?= $canMark && $c['code'] !== '–' ? '' : 'disabled' ?> @click="edit(<?= (int) $e['id'] ?>, '<?= $d ?>', '<?= e($statusFor[$c['code']] ?? '') ?>', $event)" title="<?= e($d . ($c['minutes'] ? ' · ' . intdiv($c['minutes'], 60) . 'h ' . ($c['minutes'] % 60) . 'm' : '')) ?>"><?= e($c['code']) ?></button></td><?php endforeach; ?>
            <td class="px-2 text-right tabular-nums"><?= rtrim(rtrim(number_format($row['totals']['present'], 1), '0'), '.') ?></td><td class="px-2 text-right tabular-nums"><?= (float) $row['totals']['leave'] ?></td><td class="px-2 text-right tabular-nums <?= $row['totals']['lop'] > 0 ? 'text-red-600' : '' ?>"><?= rtrim(rtrim(number_format($row['totals']['lop'], 1), '0'), '.') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$reg['rows']): ?><tr><td colspan="40" class="py-8 text-center text-muted">No employees for this month.</td></tr><?php endif; ?>
      </tbody></table></div>
    <div x-show="open" x-cloak class="fixed z-50 w-56 rounded-lg border border-theme surface p-2 shadow-lg" :style="'left:' + x + 'px;top:' + y + 'px'" @click.outside="open = false">
      <p class="mb-1 px-1 text-xs text-muted" x-text="date"></p>
      <?php foreach (array_merge(App\Services\AttendanceService::STATUSES, ['']) as $st): ?><button type="button" class="menu-item w-full text-xs" @click="save('<?= e($st) ?>')"><?= $st === '' ? 'Clear' : e($st) ?></button><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php if ($canManage): ?>
  <div class="mt-5 grid gap-5 lg:grid-cols-2">
    <section class="card"><div class="card-header"><h2 class="card-title">Holidays <?= e(date('Y')) ?></h2></div>
      <ul class="divide-theme text-[13px]"><?php foreach ($holidayList as $h): ?><li class="flex items-center gap-3 px-4 py-2"><span class="w-28 text-muted"><?= e(fmt_date($h['date'])) ?></span><span class="flex-1"><?= e($h['name']) ?></span><button class="btn btn-ghost btn-xs text-faint hover:text-red-600" data-action="<?= e(url('/attendance/holidays/' . $h['id'])) ?>" data-method="DELETE" aria-label="Remove holiday"><?= icon('x', 'size-3.5') ?></button></li><?php endforeach; ?><?php if (!$holidayList): ?><li class="px-4 py-6 text-center text-sm text-muted">No holidays added yet. Holidays are paid days and never count as absence.</li><?php endif; ?></ul>
      <form method="post" action="<?= e(url('/attendance/holidays')) ?>" data-ajax class="flex gap-2 border-t border-theme p-3"><?= csrf_field() ?><input type="date" name="date" class="input input-sm w-40" required aria-label="Date"><input name="name" class="input input-sm" placeholder="Diwali" required aria-label="Holiday name"><button class="btn btn-secondary btn-sm" type="submit"><?= icon('plus') ?></button></form></section>
    <form method="post" action="<?= e(url('/attendance/settings')) ?>" data-ajax class="card"><?= csrf_field() ?><div class="card-header"><h2 class="card-title">Rules</h2></div>
      <div class="space-y-3 p-4"><div><span class="label">Weekly off</span><div class="flex flex-wrap gap-3"><?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $n => $l): ?><label class="flex items-center gap-1.5 text-sm"><input type="checkbox" class="checkbox" name="weekly_off[]" value="<?= $n ?>" <?= in_array($n, App\Services\AttendanceService::weeklyOff(), true) ? 'checked' : '' ?>><?= $l ?></label><?php endforeach; ?></div></div>
        <div><label class="label" for="hdm">Half day if worked less than (minutes)</label><input id="hdm" type="number" name="half_day_minutes" class="input w-32" value="<?= (int) setting('hr.half_day_minutes', 240) ?>"></div>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="checkbox mt-0.5" name="unmarked_absent" value="1" <?= setting('hr.unmarked_absent', false) ? 'checked' : '' ?>><span>Strict mode: count unmarked past working days as absent (loss of pay) for employees who use check-in. Leave off if attendance is only marked for exceptions.</span></label>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('save') ?>Save rules</button></div></form>
  </div>
  <?php endif; ?>
</div>

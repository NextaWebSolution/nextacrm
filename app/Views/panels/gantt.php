<?php defined('NX_ROOT') || exit;
$db = App\Core\App::db();
$tasks = $db->all('SELECT id, title, status, priority, start_date, due_date, progress, milestone_id FROM tasks WHERE project_id = ? AND company_id = ? AND deleted_at IS NULL AND (start_date IS NOT NULL OR due_date IS NOT NULL) ORDER BY COALESCE(start_date, due_date), id LIMIT 80', [(int) $rec['id'], App\Core\Auth::companyId()]);
$miles = $db->all('SELECT id, name, due_date, status FROM milestones WHERE project_id = ? AND company_id = ? AND deleted_at IS NULL AND due_date IS NOT NULL ORDER BY due_date', [(int) $rec['id'], App\Core\Auth::companyId()]);
if (!$tasks && !$miles) {
    echo '<section class="card"><div class="card-header"><h2 class="card-title flex items-center gap-2">' . icon('chart-gantt', 'size-4') . 'Timeline</h2></div><p class="p-6 text-center text-sm text-muted">Add start and due dates to tasks to see the project timeline.</p></section>';
    return;
}
$dates = [];
foreach ($tasks as $t) { foreach (['start_date', 'due_date'] as $k) { if ($t[$k]) $dates[] = $t[$k]; } }
foreach ($miles as $m) { $dates[] = $m['due_date']; }
foreach (['start_date', 'due_date'] as $k) { if (!empty($rec[$k])) $dates[] = $rec[$k]; }
$from = strtotime(min($dates)) - 2 * 86400;
$to = strtotime(max($dates)) + 3 * 86400;
$span = max(1, $to - $from);
$x = fn (string $d) => max(0, min(100, (strtotime($d) - $from) / $span * 100));
$colors = ['Done' => '#10b981', 'In Progress' => '#6366f1', 'Review' => '#f59e0b', 'Blocked' => '#ef4444'];
$months = [];
for ($m = strtotime(date('Y-m-01', $from)); $m <= $to; $m = strtotime('+1 month', $m)) { if ($m >= $from) $months[] = $m; }
$today = time();
?>
<section class="card">
  <div class="card-header"><h2 class="card-title flex items-center gap-2"><?= icon('chart-gantt', 'size-4') ?>Timeline</h2><span class="text-xs text-muted"><?= e(fmt_date(date('Y-m-d', $from + 2 * 86400))) ?> → <?= e(fmt_date(date('Y-m-d', $to - 3 * 86400))) ?></span></div>
  <div class="overflow-x-auto"><div class="gantt min-w-[46rem]">
    <div class="gantt-label border-b border-theme text-xs font-medium text-muted">Task</div>
    <div class="gantt-track text-[10px] text-faint"><?php foreach ($months as $m): ?><span class="absolute top-2 border-l border-theme pl-1" style="left:<?= round(($m - $from) / $span * 100, 2) ?>%"><?= e(date('M Y', $m)) ?></span><?php endforeach; ?></div>
    <?php foreach ($miles as $m): $l = $x($m['due_date']); ?>
      <div class="gantt-label font-medium"><?= icon('flag', 'size-3.5 inline text-violet-600') ?> <?= e($m['name']) ?></div>
      <div class="gantt-track"><?php if ($today >= $from && $today <= $to): ?><span class="gantt-today" style="left:<?= round(($today - $from) / $span * 100, 2) ?>%"></span><?php endif; ?><span class="absolute top-[.55rem] size-3 rotate-45 rounded-[2px] bg-violet-600" style="left:calc(<?= round($l, 2) ?>% - .375rem)" title="<?= e($m['name'] . ' · ' . fmt_date($m['due_date'])) ?>"></span></div>
    <?php endforeach; ?>
    <?php foreach ($tasks as $t):
      $s = $t['start_date'] ?: $t['due_date']; $d = $t['due_date'] ?: $t['start_date'];
      $l = $x($s); $w = max(1.2, $x(date('Y-m-d', strtotime($d) + 86400)) - $l);
      $late = $t['status'] !== 'Done' && $t['due_date'] && $t['due_date'] < date('Y-m-d');
      $col = $late ? '#ef4444' : ($colors[$t['status']] ?? '#94a3b8'); ?>
      <a class="gantt-label hover:underline" href="<?= e(url('/r/tasks/' . $t['id'])) ?>"><?= e($t['title']) ?></a>
      <div class="gantt-track"><?php if ($today >= $from && $today <= $to): ?><span class="gantt-today" style="left:<?= round(($today - $from) / $span * 100, 2) ?>%"></span><?php endif; ?>
        <span class="gantt-bar" style="left:<?= round($l, 2) ?>%;width:<?= round($w, 2) ?>%;background:<?= $col ?>" title="<?= e($t['title'] . ' · ' . fmt_date($s) . ' → ' . fmt_date($d) . ' · ' . $t['status']) ?>"><?= (int) $t['progress'] ?>%</span></div>
    <?php endforeach; ?>
  </div></div>
  <p class="flex flex-wrap gap-4 border-t border-theme px-4 py-2 text-xs text-muted"><span><i class="mr-1 inline-block size-2 rounded-full bg-slate-400"></i>To do</span><span><i class="mr-1 inline-block size-2 rounded-full bg-indigo-500"></i>In progress</span><span><i class="mr-1 inline-block size-2 rounded-full bg-emerald-500"></i>Done</span><span><i class="mr-1 inline-block size-2 rounded-full bg-red-500"></i>Overdue</span><span><i class="mr-1 inline-block h-3 w-0.5 bg-red-500"></i>Today</span></p>
</section>

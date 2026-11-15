<?php defined('NX_ROOT') || exit; App\Core\View::share('extraScripts', ['js/chart.min.js']); App\Core\View::share('breadcrumbs', ['Reports' => url('/reports')]); $chart = $data['chart']; ?>
<div class="mx-auto max-w-6xl">
  <div class="mb-4 flex flex-wrap items-end gap-3">
    <div class="min-w-0 flex-1"><h1 class="text-xl font-semibold tracking-tight"><?= e($report['title']) ?></h1><p class="text-sm text-muted"><?= e($report['desc']) ?></p></div>
    <form method="get" class="flex flex-wrap items-end gap-2">
      <?php if (!in_array($key, ['pipeline', 'aging', 'profitability'], true)): ?>
        <div><label class="label" for="from">From</label><input id="from" type="date" name="from" class="input input-sm" value="<?= e($from) ?>"></div>
        <div><label class="label" for="to">To</label><input id="to" type="date" name="to" class="input input-sm" value="<?= e($to) ?>"></div>
        <button class="btn btn-secondary btn-sm" type="submit">Apply</button>
      <?php endif; ?>
      <?php if (can($report['module'], 'export')): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('/reports/' . $key, ['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>"><?= icon('download') ?>CSV</a><?php endif; ?>
    </form>
  </div>
  <?php if ($chart && $data['rows']): ?>
    <section class="card mb-4"><div class="h-72 p-3" x-data="chart(<?= ej(['type' => $chart['type'], 'money' => !in_array($key, ['time', 'tickets', 'leads'], true), 'labels' => $chart['labels'], 'datasets' => [['label' => $chart['label'], 'data' => $chart['data']]]]) ?>)"><canvas x-ref="canvas" role="img" aria-label="<?= e($report['title']) ?> chart"></canvas></div></section>
  <?php endif; ?>
  <section class="card overflow-hidden">
    <?php if (!$data['rows']): ?><p class="px-6 py-14 text-center text-sm text-muted">No data for this period.</p>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="data-table">
      <thead><tr><?php foreach ($data['columns'] as $i => $c): ?><th class="<?= $i > 0 ? 'text-right' : '' ?>"><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($data['rows'] as $r): ?><tr><?php foreach (array_values($r) as $i => $v): ?><td class="<?= $i > 0 ? 'text-right tabular-nums' : 'font-medium' ?>"><?= e($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
      <?php if (!empty($data['totals'])): ?><tfoot><tr><?php foreach (array_values($data['totals']) as $i => $v): ?><td class="<?= $i > 0 ? 'text-right tabular-nums' : '' ?>"><?= e($v) ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?>
    </table></div>
    <?php endif; ?>
  </section>
</div>

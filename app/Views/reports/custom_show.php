<?php defined('NX_ROOT') || exit; App\Core\View::share('extraScripts', ['js/chart.min.js']); ?>
<div class="mx-auto max-w-5xl">
  <div class="mb-5 flex flex-wrap items-center gap-3">
    <div class="flex-1"><h1 class="text-xl font-semibold tracking-tight"><?= e($rep['name']) ?></h1><p class="mt-1 text-sm text-muted"><?= e(App\Services\EntityRegistry::all()[$cfg['entity']]['label'] ?? '') ?> · <?= e($res['metric_label']) ?> by <?= e(strtolower($res['group_label'])) ?><?= $rep['schedule'] !== 'none' ? ' · e-mailed ' . e($rep['schedule']) : '' ?></p></div>
    <a class="btn btn-secondary" href="<?= e(url('/reports/custom/' . (int) $rep['id'], ['format' => 'csv'])) ?>"><?= icon('download') ?>CSV</a>
    <?php if ($canEdit): ?><a class="btn btn-secondary" href="<?= e(url('/reports/builder/' . (int) $rep['id'])) ?>"><?= icon('pencil') ?>Edit</a><?php endif; ?>
  </div>
  <?php if ($cfg['chart'] !== 'none' && $res['rows']): ?>
    <section class="card mb-4"><div class="h-80 p-3" x-data="chart(<?= ej(['type' => $cfg['chart'], 'money' => false, 'labels' => array_column($res['rows'], 'label'), 'datasets' => [['label' => $res['metric_label'], 'data' => array_column($res['rows'], 'value')]]]) ?>)"><canvas x-ref="canvas" role="img" aria-label="<?= e($rep['name']) ?> chart"></canvas></div></section>
  <?php endif; ?>
  <section class="card overflow-x-auto"><table class="data-table"><thead><tr><th><?= e($res['group_label']) ?></th><th class="text-right"><?= e($res['metric_label']) ?></th></tr></thead><tbody>
    <?php foreach ($res['rows'] as $row): ?><tr><td><?= e($row['label']) ?></td><td class="text-right tabular-nums"><?= e(number_format($row['value'], 2)) ?></td></tr><?php endforeach; ?>
    <?php if (!$res['rows']): ?><tr><td colspan="2" class="py-8 text-center text-muted">No data for this report.</td></tr><?php endif; ?>
  </tbody><tfoot><tr><td class="font-medium">Total</td><td class="text-right font-medium tabular-nums"><?= e(number_format($res['total'], 2)) ?></td></tr></tfoot></table></section>
</div>

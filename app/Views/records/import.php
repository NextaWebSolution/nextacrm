<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', [$svc->def['label'] => url('/r/' . $entity)]); ?>
<div class="mx-auto max-w-4xl">
  <h1 class="mb-1 text-xl font-semibold tracking-tight"><?= e($title) ?></h1>
  <p class="mb-5 text-sm text-muted">Upload a CSV file, map its columns to fields, validate, then import. Related records (e.g. Customer) can be referenced by ID or exact name; users by e-mail or name.</p>
  <?php if ($stage === 'upload'): ?>
    <form method="post" action="<?= e(url('/r/' . $entity . '/import')) ?>" enctype="multipart/form-data" class="card p-6">
      <?= csrf_field() ?>
      <label class="label" for="file">CSV file (UTF-8, comma, semicolon or tab separated, max 5,000 rows)</label>
      <input id="file" type="file" name="file" accept=".csv,text/csv" class="input py-1.5" required>
      <div class="mt-5 rounded-lg bg-surface-2 p-4 text-xs text-muted"><p class="font-medium text-[var(--text)]">Available fields</p><p class="mt-1 leading-relaxed"><?= e(implode(', ', array_map(fn ($f) => $f['label'] . (!empty($f['required']) ? ' *' : ''), $fields))) ?></p></div>
      <div class="mt-5 flex justify-end"><button type="submit" class="btn btn-primary"><?= icon('upload') ?>Upload &amp; map columns</button></div>
    </form>
  <?php else: ?>
    <?php if (!empty($result)): ?>
      <div class="mb-4 rounded-xl border p-4 text-sm <?= $result['errors'] ? 'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-950/30' : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-950/30' ?>">
        <p class="font-medium"><?= !empty($dry) ? 'Validation: ' . (int) $result['created'] . ' row(s) ready to import' : (int) $result['created'] . ' record(s) imported' ?><?= $result['errors'] ? ', ' . count($result['errors']) . ' row(s) with problems' : '' ?>.</p>
        <?php if ($result['errors']): ?><ul class="mt-2 max-h-48 space-y-1 overflow-y-auto text-xs"><?php foreach (array_slice($result['errors'], 0, 100) as $er): ?><li><span class="font-medium">Row <?= (int) $er['row'] ?>:</span> <?= e(implode(' ', $er['errors'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($stage !== 'done'): ?>
    <form method="post" action="<?= e(url('/r/' . $entity . '/import/run')) ?>" class="card">
      <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="card-header"><h2 class="card-title">Map <?= count($headers) ?> columns · <?= (int) $rowCount ?> rows</h2></div>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead><tr><th>CSV column</th><th>Sample values</th><th class="w-64">Import into</th></tr></thead>
          <tbody>
            <?php foreach ($headers as $i => $h): ?>
              <tr><td class="font-medium"><?= e($h) ?></td><td class="max-w-xs truncate text-xs text-muted"><?= e(implode(' · ', array_filter(array_map(fn ($r) => $r[$i] ?? '', $sample)))) ?></td>
                <td><select name="mapping[<?= $i ?>]" class="select input-sm"><option value="">— Skip —</option><?php foreach ($fields as $n => $f): ?><option value="<?= e($n) ?>" <?= ($mapping[$i] ?? '') === $n ? 'selected' : '' ?>><?= e($f['label']) ?></option><?php endforeach; ?></select></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="flex justify-end gap-2 border-t border-theme p-4">
        <a href="<?= e(url('/r/' . $entity . '/import')) ?>" class="btn btn-ghost">Start over</a>
        <button type="submit" name="mode" value="validate" class="btn btn-secondary"><?= icon('list-checks') ?>Validate</button>
        <button type="submit" name="mode" value="import" class="btn btn-primary"><?= icon('upload') ?>Import</button>
      </div>
    </form>
    <?php else: ?><a href="<?= e(url('/r/' . $entity)) ?>" class="btn btn-primary">Go to <?= e(strtolower($svc->def['label'])) ?></a><?php endif; ?>
  <?php endif; ?>
</div>

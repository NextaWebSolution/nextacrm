<?php defined('NX_ROOT') || exit;
use App\Services\EntityRegistry;
use App\Services\FieldRenderer;
$def = $svc->def;
$fields = EntityRegistry::visibleFields($def);
$cols = array_values(array_filter($columns, fn ($c) => isset($fields[$c])));
$rows = $result['rows'];
$sort = $params['sort'] ?? '';
$dir = $params['dir'] ?? '';
$inlineTypes = ['select', 'user', 'date', 'stage'];
$sub = $def['subtitle'] ?? null;
$titleField = $def['title'];
?>
<?php if (!$rows): ?>
  <div class="card flex flex-col items-center px-6 py-16 text-center">
    <span class="grid size-12 place-items-center rounded-xl bg-[color-mix(in_oklab,var(--text)_5%,transparent)] text-muted"><?= icon($def['icon'], 'size-5') ?></span>
    <?php if (($params['q'] ?? '') !== '' || !empty($params['filters'])): ?>
      <h3 class="mt-4 font-semibold">No matching <?= e(strtolower($def['label'])) ?></h3><p class="mt-1 text-sm text-muted">Try a different search or remove some filters.</p>
      <button type="button" class="btn btn-secondary mt-4" @click="clearAll()">Clear filters</button>
    <?php else: ?>
      <h3 class="mt-4 font-semibold">No <?= e(strtolower($def['label'])) ?> yet</h3><p class="mt-1 max-w-sm text-sm text-muted">Create your first <?= e(strtolower($def['singular'])) ?><?= can($def['module'], 'import') ? ' or import them from a CSV file' : '' ?>.</p>
      <?php if (can($def['module'], 'create')): ?>
        <div class="mt-4 flex gap-2">
          <?php if (!empty($def['document'])): ?><a href="<?= e(url('/documents/' . $def['document'] . '/new')) ?>" class="btn btn-primary"><?= icon('plus') ?>New <?= e(strtolower($def['singular'])) ?></a>
          <?php else: ?><button type="button" class="btn btn-primary" @click="create()"><?= icon('plus') ?>New <?= e(strtolower($def['singular'])) ?></button><?php endif; ?>
          <?php if (can($def['module'], 'import')): ?><a href="<?= e(url('/r/' . $entity . '/import')) ?>" class="btn btn-secondary"><?= icon('upload') ?>Import</a><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>
<!-- Desktop table -->
<div class="card hidden overflow-hidden md:block">
  <div class="max-h-[calc(100vh-17rem)] overflow-auto">
    <table class="data-table">
      <thead><tr>
        <th class="w-10"><input type="checkbox" class="checkbox" @change="toggleAll($event)" :checked="selected.length && selected.length === <?= count($rows) ?>" aria-label="Select all"></th>
        <?php foreach ($cols as $c): $f = $fields[$c]; $sortable = empty($f['custom']) || true; ?>
          <th class="<?= in_array($f['type'], ['money', 'decimal', 'number', 'duration'], true) ? 'text-right' : '' ?>">
            <button type="button" class="inline-flex items-center gap-1 hover:text-[var(--text)]" @click="sortBy('<?= e($c) ?>')"><?= e($f['label']) ?>
              <?php if ($sort === $c): ?><span class="text-primary-600"><?= icon($dir === 'desc' ? 'arrow-down' : 'arrow-up', 'size-3') ?></span><?php else: ?><span class="text-faint opacity-0 group-hover:opacity-100"><?= icon('arrow-up-down', 'size-3') ?></span><?php endif; ?>
            </button>
          </th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $row):
          $canEdit = can($def['module'], 'edit', $row); $href = url('/r/' . $entity . '/' . $row['id']); ?>
          <tr data-row-id="<?= (int) $row['id'] ?>" :class="isSel(<?= (int) $row['id'] ?>) && 'selected'" data-href="<?= e($href) ?>" class="cursor-pointer">
            <td data-no-nav><input type="checkbox" class="checkbox" value="<?= (int) $row['id'] ?>" x-model="selected" aria-label="Select row"></td>
            <?php foreach ($cols as $i => $c): $f = $fields[$c]; $align = in_array($f['type'], ['money', 'decimal', 'number', 'duration'], true) ? 'text-right' : ''; ?>
              <?php if ($c === $titleField || ($i === 0 && $c !== $titleField && !in_array($titleField, $cols, true))): ?>
                <td class="max-w-[22rem]"><a href="<?= e($href) ?>" class="block truncate font-medium hover:underline"><?= e($row['_title']) ?></a></td>
              <?php elseif ($canEdit && in_array($f['type'], $inlineTypes, true) && empty($f['readonly']) && empty($f['system'])): ?>
                <td data-no-nav class="<?= $align ?>" x-data="inlineField(<?= ej(['url' => url('/x/r/' . $entity . '/' . $row['id']), 'field' => $c, 'type' => $f['type'], 'value' => $row[$c] ?? '', 'editable' => true]) ?>)">
                  <div x-show="!editing" @click="start()" class="inline-field -my-1 cursor-pointer" x-ref="display"><?= FieldRenderer::html($f, $row, $svc) ?></div>
                  <div x-show="editing" x-cloak @click.outside="editing && save()">
                    <?php if ($f['type'] === 'date'): ?><input type="date" class="input input-sm w-36" x-model="value" data-inline-input @keydown.enter="save()" @keydown.escape="cancel()" @change="save()">
                    <?php else: ?><select class="select input-sm w-40" x-model="value" data-inline-input @change="save()" @keydown.escape="cancel()"><option value="">—</option><?php foreach (App\Services\LookupService::fieldOptions($f) as $o): ?><option value="<?= e($o['value']) ?>"><?= e(($o['group'] ?? '') ? $o['group'] . ' · ' . $o['label'] : $o['label']) ?></option><?php endforeach; ?></select><?php endif; ?>
                  </div>
                </td>
              <?php else: ?>
                <td class="<?= $align ?> max-w-[18rem] truncate"><?= FieldRenderer::html($f, $row, $svc) ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if (!empty($result['aggregates'])): ?>
      <tfoot><tr><td></td>
        <?php foreach ($cols as $c): ?><td class="<?= isset($result['aggregates'][$c]) ? 'text-right tabular-nums' : '' ?>"><?= isset($result['aggregates'][$c]) ? e($fields[$c]['type'] === 'duration' ? FieldRenderer::duration((int) $result['aggregates'][$c]) : money($result['aggregates'][$c])) : '' ?></td><?php endforeach; ?>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Mobile cards -->
<div class="space-y-2 md:hidden">
  <?php foreach ($rows as $row): $statusField = isset($fields['status']) ? 'status' : (isset($fields['stage_id']) ? 'stage_id' : null); ?>
    <div class="card flex items-start gap-3 p-3.5 active:bg-[color-mix(in_oklab,var(--text)_3%,transparent)]" data-href="<?= e(url('/r/' . $entity . '/' . $row['id'])) ?>" data-row-id="<?= (int) $row['id'] ?>">
      <input type="checkbox" class="checkbox mt-1" value="<?= (int) $row['id'] ?>" x-model="selected" data-no-nav aria-label="Select">
      <div class="min-w-0 flex-1">
        <div class="flex items-start justify-between gap-2"><span class="truncate font-medium"><?= e($row['_title']) ?></span><?= $statusField ? FieldRenderer::html($fields[$statusField], $row, $svc) : '' ?></div>
        <?php if ($sub && !empty($row[$sub])): ?><p class="mt-0.5 truncate text-xs text-muted"><?= e($row['_labels'][$sub] ?? $row[$sub]) ?></p><?php endif; ?>
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
          <?php $shown = 0; foreach ($cols as $c): if (in_array($c, [$titleField, $sub, $statusField], true) || ($row[$c] ?? null) === null || $row[$c] === '' || $shown >= 3) continue; $shown++; ?>
            <span class="inline-flex items-center gap-1"><span class="text-faint"><?= e($fields[$c]['label']) ?>:</span> <?= FieldRenderer::html($fields[$c], $row, $svc) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($result['pages'] > 1 || $result['total'] > 10): ?>
<div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-muted">
  <span>Showing <?= number_format(($result['page'] - 1) * $result['per_page'] + 1) ?>–<?= number_format(min($result['total'], $result['page'] * $result['per_page'])) ?> of <?= number_format($result['total']) ?></span>
  <div class="flex items-center gap-1">
    <button type="button" class="btn btn-secondary btn-sm" <?= $result['page'] <= 1 ? 'disabled' : '' ?> @click="goPage(<?= $result['page'] - 1 ?>)"><?= icon('chevron-left') ?><span class="hidden sm:inline">Previous</span></button>
    <?php $start = max(1, $result['page'] - 2); $end = min($result['pages'], $start + 4); for ($p = $start; $p <= $end; $p++): ?>
      <button type="button" class="btn btn-sm hidden sm:inline-flex <?= $p === $result['page'] ? 'btn-primary' : 'btn-ghost' ?>" @click="goPage(<?= $p ?>)"><?= $p ?></button>
    <?php endfor; ?>
    <button type="button" class="btn btn-secondary btn-sm" <?= $result['page'] >= $result['pages'] ? 'disabled' : '' ?> @click="goPage(<?= $result['page'] + 1 ?>)"><span class="hidden sm:inline">Next</span><?= icon('chevron-right') ?></button>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

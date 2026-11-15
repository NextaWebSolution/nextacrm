<?php defined('NX_ROOT') || exit;
use App\Core\View;
use App\Services\EntityRegistry;
$def = $svc->def;
$isEdit = $record !== null;
$fields = EntityRegistry::visibleFields($def);
$quickList = $quick ? $def['quick'] : null;
$groups = [];
foreach ($fields as $name => $f) {
    if (!empty($f['system']) || !empty($f['readonly']) || ($f['form'] ?? true) === false) continue;
    if ($quickList !== null && !in_array($name, $quickList, true) && !isset($prefill[$name])) continue;
    if ($f['type'] === 'hidden' && !isset($prefill[$name]) && !($isEdit && ($record[$name] ?? null))) continue;
    $groups[$quickList ? 'Details' : ($f['group'] ?? 'Details')][$name] = $f;
}
$action = $isEdit ? url('/r/' . $entity . '/' . $record['id']) : url('/r/' . $entity);
?>
<form action="<?= e($action) ?>" method="post" class="flex min-h-0 flex-1 flex-col" x-data="recordForm" @submit.prevent="submit()" data-entity="<?= e($entity) ?>" data-mode="<?= $isEdit ? 'edit' : 'create' ?>" novalidate>
  <?= csrf_field() ?>
  <div class="flex items-center gap-3 border-b border-theme px-5 py-4">
    <span class="grid size-9 place-items-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300"><?= icon($def['icon'], 'size-4.5') ?></span>
    <div class="min-w-0 flex-1"><h2 class="truncate text-base font-semibold"><?= e(($isEdit ? 'Edit ' : 'New ') . strtolower($def['singular'])) ?></h2><?php if ($isEdit): ?><p class="truncate text-xs text-muted"><?= e($record['_title']) ?></p><?php endif; ?></div>
    <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="$dispatch('drawer-close')" aria-label="Close"><?= icon('x') ?></button>
  </div>
  <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
    <?php foreach ($groups as $gname => $gfields): ?>
      <fieldset>
        <?php if (count($groups) > 1): ?><legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-faint"><?= e($gname) ?></legend><?php endif; ?>
        <div class="grid grid-cols-1 gap-x-4 gap-y-3.5 sm:grid-cols-2">
          <?php foreach ($gfields as $name => $f):
            $value = $isEdit ? ($record[$name] ?? null) : ($prefill[$name] ?? ($f['default'] ?? null));
            if (!$isEdit && $name === 'assigned_to' && !isset($prefill[$name])) $value = user()['id'];
            $display = $isEdit ? ($record['_labels'][$name] ?? null) : (isset($prefill[$name]) && $f['type'] === 'relation' ? (App\Services\LookupService::titles($f['relation'], [(int) $prefill[$name]])[(int) $prefill[$name]] ?? null) : null);
            $full = ($f['width'] ?? '') === 'full' || in_array($f['type'], ['textarea', 'multiselect'], true);
            if ($f['type'] === 'hidden'): ?>
              <?= View::partial('partials/field_input', ['f' => $f, 'value' => $value, 'display' => null]) ?>
            <?php continue; endif; ?>
            <div data-field class="<?= $full ? 'sm:col-span-2' : '' ?>">
              <label class="label" for="f_<?= e($name) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="text-red-500">*</span>' : '' ?></label>
              <?= View::partial('partials/field_input', ['f' => $f, 'value' => $value, 'display' => $display]) ?>
            </div>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php endforeach; ?>
    <?php if ($quickList !== null): ?><p class="text-xs text-faint">Quick create shows the essentials. You can fill in everything else on the record page.</p><?php endif; ?>
  </div>
  <div class="flex items-center justify-end gap-2 border-t border-theme bg-surface-2 px-5 py-3">
    <button type="button" class="btn btn-secondary" @click="$dispatch('drawer-close')">Cancel</button>
    <button type="submit" class="btn btn-primary" :disabled="saving"><span x-show="saving" x-cloak><?= icon('loader-circle', 'size-4 animate-spin') ?></span><?= $isEdit ? 'Save changes' : 'Create ' . e(strtolower($def['singular'])) ?></button>
  </div>
</form>

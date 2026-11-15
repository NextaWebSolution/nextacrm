<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<section class="card" x-data="{ open: false, type: 'text' }">
  <div class="card-header"><h2 class="card-title">Custom fields</h2><button type="button" class="btn btn-primary btn-sm" @click="open = !open"><?= icon('plus') ?>Add field</button></div>
  <form x-show="open" x-cloak method="post" action="<?= e(url('/settings/fields')) ?>" class="grid gap-3 border-b border-theme p-4 sm:grid-cols-4">
    <?= csrf_field() ?>
    <div><label class="label">Module</label><select name="entity" class="select" required><?php foreach ($entities as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="label">Label</label><input name="label" class="input" required placeholder="e.g. Tech stack"></div>
    <div><label class="label">Type</label><select name="type" class="select" x-model="type"><?php foreach (App\Services\EntityRegistry::CUSTOM_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="label">Order</label><input type="number" name="sort" class="input" value="0"></div>
    <div class="sm:col-span-2" x-show="type === 'select' || type === 'multiselect'"><label class="label">Options (one per line)</label><textarea name="options" rows="3" class="textarea"></textarea></div>
    <div class="flex items-end gap-4 sm:col-span-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="required" value="1">Required</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="show_in_list" value="1" checked>Show in list</label><button class="btn btn-primary ml-auto" type="submit">Create field</button></div>
  </form>
  <?php if (!$fields): ?><p class="px-4 py-10 text-center text-sm text-muted">No custom fields yet. They appear in forms, detail pages, lists, filters, exports, imports and the API.</p>
  <?php else: ?>
  <table class="data-table"><thead><tr><th>Module</th><th>Label</th><th>Key</th><th>Type</th><th>Flags</th><th></th></tr></thead><tbody>
    <?php foreach ($fields as $f): ?><tr><td><?= e($entities[$f['entity']] ?? $f['entity']) ?></td><td class="font-medium"><?= e($f['label']) ?></td><td><code class="text-xs"><?= e($f['name']) ?></code></td><td><?= e(App\Services\EntityRegistry::CUSTOM_TYPES[$f['type']] ?? $f['type']) ?></td><td class="text-xs text-muted"><?= $f['required'] ? 'Required ' : '' ?><?= $f['show_in_list'] ? 'In list' : '' ?></td>
      <td class="text-right"><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" data-action="<?= e(url('/settings/fields/' . $f['id'])) ?>" data-method="DELETE" data-confirm="Delete this field definition?" data-danger><?= icon('trash') ?></button></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
</div></div>

<?php defined('NX_ROOT') || exit;
$raw = App\Core\App::db()->value('SELECT checklist FROM tasks WHERE id = ?', [(int) $rec['id']]);
$items = json_decode((string) $raw, true) ?: [];
$canEdit = App\Core\Permissions::can('tasks', 'edit', $rec);
?>
<section class="card" x-data="checklist(<?= ej(['url' => url('/r/tasks/' . (int) $rec['id'] . '/checklist'), 'items' => $items, 'canEdit' => $canEdit]) ?>)">
  <div class="card-header"><h2 class="card-title flex items-center gap-2"><?= icon('list-todo', 'size-4') ?>Checklist</h2><span class="text-xs text-muted" x-text="items.length ? done() + ' / ' + items.length : ''"></span></div>
  <div class="p-4">
    <span class="progress mb-3 block" x-show="items.length"><span :style="'width:' + pct() + '%'"></span></span>
    <ul class="space-y-1">
      <template x-for="(it, i) in items" :key="i">
        <li class="group flex items-center gap-2 rounded-md px-1 py-1 hover:bg-[color-mix(in_oklab,var(--text)_3%,transparent)]">
          <input type="checkbox" class="checkbox" :checked="it.d" :disabled="!canEdit" @change="it.d = $event.target.checked; save()" :aria-label="it.t">
          <span class="flex-1 text-[13px]" :class="it.d && 'text-faint line-through'" x-text="it.t"></span>
          <button type="button" x-show="canEdit" class="hidden text-faint hover:text-red-600 group-hover:inline" @click="items.splice(i, 1); save()" aria-label="Remove item"><?= icon('x', 'size-3.5') ?></button>
        </li>
      </template>
    </ul>
    <form x-show="canEdit" class="mt-2 flex gap-2" @submit.prevent="add()"><input class="input input-sm" x-model="draft" placeholder="Add an item…" maxlength="300" aria-label="New checklist item"><button class="btn btn-secondary btn-sm" type="submit"><?= icon('plus') ?></button></form>
    <p x-show="!items.length && !canEdit" class="text-sm text-muted">No checklist.</p>
  </div>
</section>

<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<section class="card" x-data="{ edit: null }">
  <div class="card-header"><h2 class="card-title">Teams</h2><button type="button" class="btn btn-primary btn-sm" @click="edit = 0"><?= icon('plus') ?>New team</button></div>
  <ul class="divide-theme">
    <?php foreach ($teams as $t): ?>
      <li class="flex items-center gap-3 px-4 py-3"><div class="min-w-0 flex-1"><p class="font-medium"><?= e($t['name']) ?></p><p class="text-xs text-muted"><?= count($t['members']) ?> members<?= $t['description'] ? ' · ' . e($t['description']) : '' ?></p></div>
        <button type="button" class="btn btn-ghost btn-sm" @click="edit = <?= (int) $t['id'] ?>">Edit</button>
        <button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" data-action="<?= e(url('/settings/teams/' . $t['id'])) ?>" data-method="DELETE" data-confirm="Delete this team?" data-danger><?= icon('trash') ?></button></li>
    <?php endforeach; ?>
    <?php if (!$teams): ?><li class="px-4 py-8 text-center text-sm text-muted">No teams yet. Teams power the “Team” permission scope.</li><?php endif; ?>
  </ul>
  <?php foreach (array_merge([['id' => 0, 'name' => '', 'description' => '', 'members' => []]], $teams) as $t): ?>
    <template x-if="edit === <?= (int) $t['id'] ?>"><div class="overlay" @click="edit = null"></div></template>
    <form x-show="edit === <?= (int) $t['id'] ?>" x-cloak class="modal p-5" method="post" action="<?= e(url('/settings/teams')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
      <h3 class="font-semibold"><?= $t['id'] ? 'Edit team' : 'New team' ?></h3>
      <div class="mt-4 space-y-3"><div><label class="label">Name</label><input name="name" class="input" value="<?= e($t['name']) ?>" required></div><div><label class="label">Description</label><input name="description" class="input" value="<?= e($t['description']) ?>"></div>
        <div><label class="label">Members</label><div class="max-h-56 space-y-1.5 overflow-y-auto rounded-lg border border-theme p-3"><?php foreach ($users as $u): ?><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="members[]" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $t['members'], true) ? 'checked' : '' ?>><?= e($u['name']) ?></label><?php endforeach; ?></div></div></div>
      <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-secondary" @click="edit = null">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  <?php endforeach; ?>
</section>
</div></div>

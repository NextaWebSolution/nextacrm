<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<form method="post" action="<?= e(url($role['id'] ? '/settings/roles/' . $role['id'] : '/settings/roles')) ?>" class="space-y-5" x-data="roleMatrix">
  <?= csrf_field() ?>
  <section class="card grid gap-4 p-5 sm:grid-cols-2">
    <div><label class="label">Role name</label><input name="name" class="input" value="<?= e($role['name']) ?>" required></div>
    <div><label class="label">Description</label><input name="description" class="input" value="<?= e($role['description']) ?>"></div>
  </section>
  <?php if ($role['is_admin']): ?>
    <div class="card p-5 text-sm text-muted">Administrators always have full access to every module and to Settings.</div>
  <?php else: ?>
  <section class="card overflow-hidden">
    <div class="card-header"><h2 class="card-title">Permissions</h2>
      <div class="flex gap-1 text-xs"><button type="button" class="btn btn-ghost btn-xs" @click="setAll('view','all'); setAll('edit','all'); setAll('create', 1)">Full access</button><button type="button" class="btn btn-ghost btn-xs" @click="setAll('view','all'); setAll('edit','none'); setAll('delete','none'); setAll('create', 0); setAll('import',0); setAll('export',0); setAll('approve',0); setAll('assign',0); setAll('manage',0)">Read-only</button></div></div>
    <div class="overflow-x-auto"><table class="data-table">
      <thead><tr><th>Module</th><?php foreach (App\Core\Permissions::ACTIONS as $a): ?><th class="text-center capitalize"><?= e($a) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($modules as $mod => $label): ?>
          <tr>
            <td class="whitespace-nowrap font-medium"><?= e($label) ?></td>
            <?php foreach (App\Core\Permissions::ACTIONS as $a): $v = $perms[$mod][$a] ?? null; ?>
              <td class="text-center">
                <?php if (in_array($a, App\Core\Permissions::SCOPED, true)): ?>
                  <select name="perm[<?= e($mod) ?>][<?= $a ?>]" data-action-col="<?= $a ?>" class="select input-sm w-24 text-xs"><?php foreach (App\Core\Permissions::LEVELS as $l): ?><option value="<?= $l ?>" <?= ($v ?? 'none') === $l ? 'selected' : '' ?>><?= ucfirst($l) ?></option><?php endforeach; ?></select>
                <?php else: ?>
                  <input type="checkbox" class="checkbox" name="perm[<?= e($mod) ?>][<?= $a ?>]" value="1" data-action-col="<?= $a ?>" <?= $v ? 'checked' : '' ?> aria-label="<?= e($label . ' ' . $a) ?>">
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php endif; ?>
  <div class="flex justify-end gap-2"><a href="<?= e(url('/settings/roles')) ?>" class="btn btn-secondary">Cancel</a><button class="btn btn-primary" type="submit">Save role</button></div>
</form>
</div></div>

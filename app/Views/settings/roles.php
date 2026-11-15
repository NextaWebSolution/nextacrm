<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<section class="card">
  <div class="card-header"><h2 class="card-title">Roles</h2><a href="<?= e(url('/settings/roles/new')) ?>" class="btn btn-primary btn-sm"><?= icon('plus') ?>New role</a></div>
  <ul class="divide-theme">
    <?php foreach ($roles as $r): ?>
      <li class="flex items-center gap-3 px-4 py-3">
        <span class="grid size-9 place-items-center rounded-lg <?= $r['is_admin'] ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'bg-surface-2 text-muted' ?>"><?= icon($r['is_admin'] ? 'shield' : 'key-round') ?></span>
        <div class="min-w-0 flex-1"><p class="font-medium"><?= e($r['name']) ?> <?= $r['is_admin'] ? badge('Full access', 'amber') : '' ?></p><p class="truncate text-xs text-muted"><?= e($r['description']) ?></p></div>
        <span class="text-xs text-muted"><?= (int) $r['user_count'] ?> users</span>
        <a href="<?= e(url('/settings/roles/' . $r['id'])) ?>" class="btn btn-ghost btn-sm">Edit</a>
        <?php if (!$r['is_admin'] && !$r['is_system']): ?><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" data-action="<?= e(url('/settings/roles/' . $r['id'])) ?>" data-method="DELETE" data-confirm="Delete the role “<?= e($r['name']) ?>”?" data-danger aria-label="Delete"><?= icon('trash') ?></button><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<p class="text-xs text-muted">View/Edit/Delete use record scope: <b>Own</b> (assigned to or created by the user), <b>Team</b> (anyone in the user's teams), <b>All</b>. Project members can always see their projects and tasks.</p>
</div></div>

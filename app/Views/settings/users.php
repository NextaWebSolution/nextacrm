<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<section class="card" x-data="{ edit: null }">
  <div class="card-header"><h2 class="card-title">Users</h2><button type="button" class="btn btn-primary btn-sm" @click="edit = 'new'"><?= icon('user-plus') ?>Invite user</button></div>
  <div class="overflow-x-auto"><table class="data-table">
    <thead><tr><th>Name</th><th>Role</th><th class="hidden md:table-cell">Status</th><th class="hidden lg:table-cell">Last sign-in</th><th class="hidden md:table-cell">2FA</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><div class="flex items-center gap-2.5"><?= App\Services\FieldRenderer::avatar($u['name'], hue_for($u['email']), 'size-8 text-[11px]') ?><div class="min-w-0"><p class="truncate font-medium"><?= e($u['name']) ?></p><p class="truncate text-xs text-muted"><?= e($u['email']) ?></p></div></div></td>
          <td><?= e($u['role_name']) ?></td>
          <td class="hidden md:table-cell"><?= badge(ucfirst($u['status']), $u['status'] === 'active' ? 'green' : ($u['status'] === 'invited' ? 'amber' : 'slate')) ?></td>
          <td class="hidden text-muted lg:table-cell"><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : '—' ?></td>
          <td class="hidden md:table-cell"><?= $u['two_factor_enabled'] ? '<span class="text-emerald-600">' . icon('shield-check') . '</span>' : '<span class="text-faint">—</span>' ?></td>
          <td class="text-right"><button type="button" class="btn btn-ghost btn-sm" @click="edit = <?= (int) $u['id'] ?>">Manage</button></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>

  <template x-if="edit === 'new'"><div class="overlay" @click="edit = null"></div></template>
  <form x-show="edit === 'new'" x-cloak class="modal p-5" method="post" action="<?= e(url('/settings/users')) ?>" data-ajax>
    <?= csrf_field() ?>
    <h3 class="font-semibold">Invite a user</h3><p class="mt-1 text-sm text-muted">They'll receive an e-mail to set a password (valid 72 hours).</p>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
      <div><label class="label">Full name</label><input name="name" class="input" required></div>
      <div><label class="label">E-mail</label><input name="email" type="email" class="input" required></div>
      <div><label class="label">Role</label><select name="role_id" class="select" required><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $r['name'] === 'Sales' ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="label">Job title</label><input name="job_title" class="input"></div>
      <div class="sm:col-span-2"><label class="label">Teams</label><div class="flex flex-wrap gap-3"><?php foreach ($teams as $t): ?><label class="flex items-center gap-1.5 text-sm"><input type="checkbox" class="checkbox" name="teams[]" value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></label><?php endforeach; ?></div></div>
    </div>
    <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-secondary" @click="edit = null">Cancel</button><button class="btn btn-primary" type="submit">Send invitation</button></div>
  </form>

  <?php foreach ($users as $u): ?>
    <template x-if="edit === <?= (int) $u['id'] ?>"><div class="overlay" @click="edit = null"></div></template>
    <form x-show="edit === <?= (int) $u['id'] ?>" x-cloak class="modal max-h-[85vh] overflow-y-auto p-5" method="post" action="<?= e(url('/settings/users/' . $u['id'])) ?>" data-ajax>
      <?= csrf_field() ?>
      <h3 class="font-semibold"><?= e($u['name']) ?></h3><p class="text-sm text-muted"><?= e($u['email']) ?></p>
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div><label class="label">Name</label><input name="name" class="input" value="<?= e($u['name']) ?>"></div>
        <div><label class="label">Job title</label><input name="job_title" class="input" value="<?= e($u['job_title']) ?>"></div>
        <div><label class="label">Role</label><select name="role_id" class="select"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) $u['role_id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="label">Status</label><select name="status" class="select" <?= $u['status'] === 'invited' ? 'disabled' : '' ?>><option value="active" <?= $u['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= $u['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option></select><?php if ($u['status'] === 'invited'): ?><input type="hidden" name="status" value="active"><?php endif; ?></div>
        <div class="sm:col-span-2"><label class="label">Teams</label><div class="flex flex-wrap gap-3"><?php foreach ($teams as $t): ?><label class="flex items-center gap-1.5 text-sm"><input type="checkbox" class="checkbox" name="teams[]" value="<?= (int) $t['id'] ?>" <?= in_array((string) $t['id'], array_map('strval', $u['teams']), true) ? 'checked' : '' ?>><?= e($t['name']) ?></label><?php endforeach; ?></div></div>
        <?php if (count($companies) > 1): ?><div class="sm:col-span-2"><label class="label">Company access</label><div class="flex flex-wrap gap-3"><?php foreach ($companies as $c): ?><label class="flex items-center gap-1.5 text-sm"><input type="checkbox" class="checkbox" name="companies[]" value="<?= (int) $c['id'] ?>" <?= in_array((string) $c['id'], array_map('strval', $u['companies']), true) ? 'checked' : '' ?>><?= e($c['name']) ?></label><?php endforeach; ?></div></div><?php endif; ?>
      </div>
      <div class="mt-4 flex flex-wrap gap-2 border-t border-theme pt-4 text-xs">
        <?php if ($u['status'] === 'invited'): ?><button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/users/' . $u['id'] . '/resend')) ?>">Resend invitation</button><?php endif; ?>
        <?php if ($u['two_factor_enabled']): ?><button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/users/' . $u['id'] . '/reset-2fa')) ?>" data-confirm="Reset two-factor authentication for this user?">Reset 2FA</button><?php endif; ?>
        <button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/users/' . $u['id'] . '/logout')) ?>" data-confirm="Sign this user out of all devices?">Sign out everywhere</button>
        <?php if ((int) $u['id'] !== (int) user()['id']): ?><button type="button" class="btn btn-danger-ghost btn-sm ml-auto" data-action="<?= e(url('/settings/users/' . $u['id'])) ?>" data-body="<?= ej(['delete' => 1, 'role_id' => $u['role_id'], 'status' => 'disabled', 'name' => $u['name']]) ?>" data-confirm="Remove this user? Their records stay; they lose access immediately." data-danger data-ok="Remove">Remove user</button><?php endif; ?>
      </div>
      <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-secondary" @click="edit = null">Close</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  <?php endforeach; ?>
</section>
</div></div>

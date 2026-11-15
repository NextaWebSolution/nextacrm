<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<section class="card" x-data="{ edit: null }">
  <div class="card-header"><h2 class="card-title">Web-to-lead forms</h2><button type="button" class="btn btn-primary btn-sm" @click="edit = 0"><?= icon('plus') ?>New form</button></div>
  <?php if (!$forms): ?><p class="px-4 py-8 text-center text-sm text-muted">Create a form and embed it on your website — submissions become leads instantly, with spam protection.</p><?php endif; ?>
  <ul class="divide-theme">
    <?php foreach ($forms as $f): $link = full_url('/forms/' . $f['token']); ?>
      <li class="px-4 py-3">
        <div class="flex flex-wrap items-center gap-3"><div class="min-w-0 flex-1"><p class="font-medium"><?= e($f['name']) ?> <?= $f['is_active'] ? badge('Active', 'green') : badge('Off', 'slate') ?></p><p class="text-xs text-muted"><?= (int) $f['submissions'] ?> submissions</p></div>
          <a href="<?= e($link) ?>" target="_blank" class="btn btn-secondary btn-sm"><?= icon('external-link') ?>Preview</a><button type="button" class="btn btn-ghost btn-sm" @click="edit = <?= (int) $f['id'] ?>">Edit</button><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" data-action="<?= e(url('/settings/forms/' . $f['id'])) ?>" data-method="DELETE" data-confirm="Delete this form?" data-danger><?= icon('trash') ?></button></div>
        <div class="mt-2 flex gap-2"><input id="emb<?= (int) $f['id'] ?>" class="input input-sm font-mono text-[11px]" readonly value="<?= e('<iframe src="' . $link . '" style="width:100%;max-width:560px;height:620px;border:0" loading="lazy" title="' . $f['name'] . '"></iframe>') ?>"><button type="button" class="btn btn-secondary btn-sm" data-copy="#emb<?= (int) $f['id'] ?>"><?= icon('copy') ?>Embed code</button></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php foreach (array_merge([['id' => 0, 'name' => 'Contact us', 'fields' => '[{"name":"name","required":true},{"name":"email","required":true},{"name":"phone"},{"name":"company_name"},{"name":"description"}]', 'assigned_to' => null, 'default_status' => 'New', 'default_source' => 'Website', 'success_message' => '', 'redirect_url' => '', 'is_active' => 1]], $forms) as $f): $sel = json_decode((string) $f['fields'], true) ?: []; $names = array_column($sel, 'name'); $req = array_column(array_filter($sel, fn ($x) => !empty($x['required'])), 'name'); ?>
    <template x-if="edit === <?= (int) $f['id'] ?>"><div class="overlay" @click="edit = null"></div></template>
    <form x-show="edit === <?= (int) $f['id'] ?>" x-cloak class="modal max-h-[85vh] overflow-y-auto p-5" method="post" action="<?= e(url('/settings/forms')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
      <h3 class="font-semibold"><?= $f['id'] ? 'Edit form' : 'New form' ?></h3>
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2"><label class="label">Form name</label><input name="name" class="input" value="<?= e($f['name']) ?>"></div>
        <div class="sm:col-span-2"><label class="label">Fields (tick “req.” for required)</label><div class="grid grid-cols-2 gap-1.5 rounded-lg border border-theme p-3"><?php foreach ($available as $k => $l): ?><div class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="fields[]" value="<?= e($k) ?>" <?= in_array($k, $names, true) ? 'checked' : '' ?>><span class="flex-1"><?= e($l) ?></span><label class="flex items-center gap-1 text-xs text-muted"><input type="checkbox" class="checkbox" name="required[]" value="<?= e($k) ?>" <?= in_array($k, $req, true) ? 'checked' : '' ?>>req.</label></div><?php endforeach; ?></div></div>
        <div><label class="label">Assign leads to</label><select name="assigned_to" class="select"><option value="">Form creator</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $f['assigned_to'] === $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="label">Lead source</label><input name="default_source" class="input" value="<?= e($f['default_source']) ?>"></div>
        <div class="sm:col-span-2"><label class="label">Thank-you message</label><input name="success_message" class="input" value="<?= e($f['success_message']) ?>"></div>
        <div class="sm:col-span-2"><label class="label">Or redirect to (https://…)</label><input name="redirect_url" class="input" value="<?= e($f['redirect_url']) ?>"></div>
        <input type="hidden" name="default_status" value="<?= e($f['default_status'] ?: 'New') ?>">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="is_active" value="1" <?= $f['is_active'] ? 'checked' : '' ?>>Active</label>
      </div>
      <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-secondary" @click="edit = null">Cancel</button><button class="btn btn-primary" type="submit">Save form</button></div>
    </form>
  <?php endforeach; ?>
</section>
</div></div>

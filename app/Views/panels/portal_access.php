<?php defined('NX_ROOT') || exit; if (!can('contacts', 'edit') || !module_enabled('contacts')) return; ?>
<section class="card">
  <div class="card-header"><h2 class="card-title">Client portal</h2><?= !empty($rec['portal_enabled']) ? badge('Active', 'green') : badge('No access', 'slate') ?></div>
  <div class="space-y-3 p-4 text-[13px]">
    <?php if (!empty($rec['portal_enabled'])): ?>
      <p class="text-muted"><?= !empty($rec['portal_last_login']) ? 'Last signed in ' . e(time_ago($rec['portal_last_login'])) : 'Invitation sent, not signed in yet.' ?></p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/r/contacts/' . (int) $rec['id'] . '/portal/invite')) ?>"><?= icon('send') ?>Resend invite</button>
        <button type="button" class="btn btn-danger-ghost btn-sm" data-action="<?= e(url('/r/contacts/' . (int) $rec['id'] . '/portal/disable')) ?>" data-confirm="Remove this contact's portal access? They will be signed out." data-danger><?= icon('lock') ?>Disable</button>
      </div>
    <?php else: ?>
      <p class="text-muted">Let <?= e($rec['first_name'] ?: $rec['name']) ?> see invoices, pay online, follow projects and raise tickets.</p>
      <button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/r/contacts/' . (int) $rec['id'] . '/portal/invite')) ?>" <?= empty($rec['email']) ? 'disabled title="Add an e-mail address first"' : '' ?>><?= icon('door-open') ?>Invite to portal</button>
    <?php endif; ?>
  </div>
</section>

<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<form method="post" action="<?= e(url('/settings/email')) ?>" class="card" x-data="{ driver: '<?= e(setting('mail.driver', 'smtp')) ?>' }">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">Outgoing e-mail</h2></div>
  <div class="grid gap-4 p-5 sm:grid-cols-2">
    <div><label class="label">Method</label><select name="driver" class="select" x-model="driver"><option value="smtp">SMTP (recommended)</option><option value="mail">PHP mail() / server sendmail</option></select></div>
    <div></div>
    <div><label class="label">From e-mail</label><input name="from_email" type="email" class="input" value="<?= e(setting('mail.from_email')) ?>" placeholder="no-reply@yourdomain.com"></div>
    <div><label class="label">From name</label><input name="from_name" class="input" value="<?= e(setting('mail.from_name')) ?>"></div>
    <template x-if="driver === 'smtp'"><div class="contents">
      <div><label class="label">SMTP host</label><input name="smtp_host" class="input" value="<?= e(setting('mail.smtp_host')) ?>" placeholder="mail.yourdomain.com"></div>
      <div class="grid grid-cols-2 gap-3"><div><label class="label">Port</label><input name="smtp_port" type="number" class="input" value="<?= (int) setting('mail.smtp_port', 587) ?>"></div><div><label class="label">Encryption</label><select name="smtp_encryption" class="select"><?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('mail.smtp_encryption') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></div>
      <div><label class="label">Username</label><input name="smtp_username" class="input" value="<?= e(setting('mail.smtp_username')) ?>" autocomplete="off"></div>
      <div><label class="label">Password</label><input name="smtp_password" type="password" class="input" placeholder="<?= $hasPassword ? '•••••••• (unchanged)' : '' ?>" autocomplete="new-password"><p class="help">Stored encrypted.</p></div>
    </div></template>
  </div>
  <div class="flex flex-wrap items-center justify-end gap-2 border-t border-theme px-5 py-3">
    <button type="button" class="btn btn-secondary" data-action="<?= e(url('/settings/email/test')) ?>" data-body="<?= ej(['to' => user()['email']]) ?>">Send test to <?= e(user()['email']) ?></button>
    <button class="btn btn-primary" type="submit">Save</button>
  </div>
</form>
<section class="card"><div class="card-header"><h2 class="card-title">Recent e-mails</h2></div>
  <?php if (!$queue): ?><p class="px-4 py-8 text-center text-sm text-muted">Nothing sent yet.</p><?php else: ?>
  <div class="overflow-x-auto"><table class="data-table"><thead><tr><th>To</th><th>Subject</th><th>Status</th><th>Queued</th></tr></thead><tbody>
    <?php foreach ($queue as $q): ?><tr><td><?= e($q['to_email']) ?></td><td class="max-w-xs truncate"><?= e($q['subject']) ?></td><td><?= badge(ucfirst($q['status']), $q['status'] === 'sent' ? 'green' : ($q['status'] === 'failed' ? 'red' : 'amber')) ?><?php if ($q['error']): ?><p class="mt-0.5 max-w-xs truncate text-xs text-red-600" title="<?= e($q['error']) ?>"><?= e($q['error']) ?></p><?php endif; ?></td><td class="text-muted"><?= e(time_ago($q['created_at'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
</div></div>

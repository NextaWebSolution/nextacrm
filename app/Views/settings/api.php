<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<?php if ($newToken): ?>
  <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/30"><p class="text-sm font-medium">Your new API token — copy it now, it won't be shown again:</p>
    <div class="mt-2 flex gap-2"><input id="newtok" class="input font-mono text-xs" value="<?= e($newToken) ?>" readonly><button type="button" class="btn btn-secondary" data-copy="#newtok"><?= icon('copy') ?>Copy</button></div></div>
<?php endif; ?>
<section class="card">
  <div class="card-header"><h2 class="card-title">API tokens</h2><a href="<?= e(url('/api/docs')) ?>" target="_blank" class="btn btn-ghost btn-sm"><?= icon('book-open') ?>API docs</a></div>
  <form method="post" action="<?= e(url('/settings/api/tokens')) ?>" class="grid gap-3 border-b border-theme p-4 sm:grid-cols-5 sm:items-end">
    <?= csrf_field() ?>
    <div class="sm:col-span-2"><label class="label">Name</label><input name="name" class="input" placeholder="e.g. Website integration" required></div>
    <div><label class="label">Acts as user</label><select name="user_id" class="select"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $u['id'] === (int) user()['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="grid grid-cols-2 gap-2"><div><label class="label">Scope</label><select name="scope" class="select"><option value="read">Read</option><option value="write">Read &amp; write</option></select></div><div><label class="label">Expires</label><select name="expires_days" class="select"><option value="0">Never</option><option value="30">30 days</option><option value="90">90 days</option><option value="365">1 year</option></select></div></div>
    <button class="btn btn-primary" type="submit">Create token</button>
  </form>
  <?php if (!$tokens): ?><p class="px-4 py-6 text-center text-sm text-muted">No tokens.</p><?php else: ?>
  <table class="data-table"><thead><tr><th>Name</th><th>Token</th><th>User</th><th>Scope</th><th>Last used</th><th></th></tr></thead><tbody>
    <?php foreach ($tokens as $t): ?><tr><td class="font-medium"><?= e($t['name']) ?></td><td><code class="text-xs"><?= e($t['token_prefix']) ?>…</code></td><td><?= e($t['user_name']) ?></td><td><?= badge(ucfirst($t['scope']), $t['scope'] === 'write' ? 'amber' : 'blue') ?></td><td class="text-xs text-muted"><?= $t['last_used_at'] ? e(time_ago($t['last_used_at'])) . ' · ' . e($t['last_used_ip']) : 'Never' ?><?= $t['expires_at'] ? '<br>expires ' . e(fmt_date(substr($t['expires_at'], 0, 10))) : '' ?></td>
      <td class="text-right"><button type="button" class="btn btn-danger-ghost btn-sm" data-action="<?= e(url('/settings/api/tokens/' . $t['id'])) ?>" data-method="DELETE" data-confirm="Revoke this token? Integrations using it will stop working." data-danger data-ok="Revoke">Revoke</button></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>

<section class="card" x-data="{ edit: null }">
  <div class="card-header"><h2 class="card-title">Outgoing webhooks</h2><button type="button" class="btn btn-primary btn-sm" @click="edit = 0"><?= icon('plus') ?>Add webhook</button></div>
  <?php if (!$webhooks): ?><p class="px-4 py-6 text-center text-sm text-muted">Send record events to Zapier, Make, n8n, Slack or your own systems.</p><?php endif; ?>
  <ul class="divide-theme">
    <?php foreach ($webhooks as $h): $ev = json_decode((string) $h['events'], true) ?: []; ?>
      <li class="flex flex-wrap items-center gap-3 px-4 py-3"><div class="min-w-0 flex-1"><p class="font-medium"><?= e($h['name']) ?> <?= $h['is_active'] ? badge('Active', 'green') : badge('Disabled', 'slate') ?></p><p class="truncate text-xs text-muted"><?= e($h['url']) ?> · <?= e(in_array('*', $ev, true) ? 'all events' : count($ev) . ' events') ?><?= $h['failure_count'] ? ' · ' . (int) $h['failure_count'] . ' recent failures' : '' ?></p></div>
        <button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/webhooks/' . $h['id'] . '/test')) ?>">Send test</button><button type="button" class="btn btn-ghost btn-sm" @click="edit = <?= (int) $h['id'] ?>">Edit</button><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" data-action="<?= e(url('/settings/webhooks/' . $h['id'])) ?>" data-method="DELETE" data-confirm="Delete this webhook?" data-danger><?= icon('trash') ?></button></li>
    <?php endforeach; ?>
  </ul>
  <?php foreach (array_merge([['id' => 0, 'name' => '', 'url' => '', 'events' => '["*"]', 'is_active' => 1]], $webhooks) as $h): $ev = json_decode((string) $h['events'], true) ?: []; ?>
    <template x-if="edit === <?= (int) $h['id'] ?>"><div class="overlay" @click="edit = null"></div></template>
    <form x-show="edit === <?= (int) $h['id'] ?>" x-cloak class="modal max-h-[85vh] overflow-y-auto p-5" method="post" action="<?= e(url('/settings/webhooks')) ?>" data-ajax>
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
      <h3 class="font-semibold"><?= $h['id'] ? 'Edit webhook' : 'New webhook' ?></h3>
      <div class="mt-4 space-y-3"><div><label class="label">Name</label><input name="name" class="input" value="<?= e($h['name']) ?>"></div><div><label class="label">Endpoint URL</label><input name="url" type="url" class="input" value="<?= e($h['url']) ?>" required placeholder="https://hooks.example.com/…"></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="is_active" value="1" <?= $h['is_active'] ? 'checked' : '' ?>>Active</label>
        <div><label class="label">Events</label><div class="max-h-60 overflow-y-auto rounded-lg border border-theme p-3"><label class="mb-2 flex items-center gap-2 text-sm font-medium"><input type="checkbox" class="checkbox" name="events[]" value="*" <?= in_array('*', $ev, true) ? 'checked' : '' ?>>All events</label><div class="grid grid-cols-2 gap-1"><?php foreach ($events as $e1): ?><label class="flex items-center gap-1.5 text-xs"><input type="checkbox" class="checkbox" name="events[]" value="<?= e($e1) ?>" <?= in_array($e1, $ev, true) ? 'checked' : '' ?>><?= e($e1) ?></label><?php endforeach; ?></div></div></div>
        <p class="text-xs text-faint">Payloads are signed: <code>X-Nexta-Signature: sha256=HMAC(secret, timestamp + "." + body)</code>. Failed deliveries retry with backoff for ~15 hours.</p></div>
      <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-secondary" @click="edit = null">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  <?php endforeach; ?>
</section>
<?php if ($deliveries): ?>
<section class="card"><div class="card-header"><h2 class="card-title">Recent deliveries</h2></div>
  <table class="data-table"><thead><tr><th>Webhook</th><th>Event</th><th>Status</th><th>Attempts</th><th>When</th></tr></thead><tbody><?php foreach ($deliveries as $d): ?><tr><td><?= e($d['name']) ?></td><td><code class="text-xs"><?= e($d['event']) ?></code></td><td><?= badge(ucfirst($d['status']) . ($d['response_code'] ? ' ' . $d['response_code'] : ''), $d['status'] === 'success' ? 'green' : ($d['status'] === 'failed' ? 'red' : 'amber')) ?></td><td><?= (int) $d['attempts'] ?></td><td class="text-muted"><?= e(time_ago($d['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php endif; ?>
</div></div>

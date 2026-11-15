<?php defined('NX_ROOT') || exit; $e = $edit ?? []; ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="space-y-5">
  <div><h1 class="text-xl font-semibold tracking-tight">Inbound e-mail</h1><p class="mt-1 text-sm text-muted">Connect a mailbox over IMAP. New messages become support tickets (customer replies are threaded by the ticket number in the subject) or leads.</p></div>
  <section class="card">
    <div class="card-header"><h2 class="card-title">Mailboxes</h2></div>
    <ul class="divide-theme">
      <?php foreach ($boxes as $b): ?>
        <li class="flex flex-wrap items-center gap-3 px-4 py-3">
          <span class="grid size-9 place-items-center rounded-lg border border-theme text-muted"><?= icon('inbox') ?></span>
          <div class="min-w-0 flex-1"><p class="font-medium"><?= e($b['name']) ?> <span class="text-xs text-muted">→ <?= $b['purpose'] === 'leads' ? 'Leads' : 'Tickets' ?></span></p>
            <p class="truncate text-xs text-muted"><?= e($b['username']) ?> · <?= e($b['host']) ?>:<?= (int) $b['port'] ?> · <?= e($b['folder']) ?> · <?= $b['last_checked_at'] ? 'checked ' . e(time_ago($b['last_checked_at'])) : 'not checked yet' ?></p>
            <?php if ($b['last_error']): ?><p class="mt-0.5 text-xs text-red-600"><?= e($b['last_error']) ?></p><?php endif; ?></div>
          <?= $b['is_active'] ? badge('Active', 'green') : badge('Paused', 'slate') ?>
          <button class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/inbox/' . $b['id'] . '/test')) ?>"><?= icon('plug') ?>Test</button>
          <button class="btn btn-secondary btn-sm" data-action="<?= e(url('/settings/inbox/' . $b['id'] . '/fetch')) ?>"><?= icon('refresh-cw') ?>Fetch now</button>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('/settings/inbox', ['edit' => $b['id']])) ?>"><?= icon('pencil') ?></a>
          <button class="btn btn-ghost btn-sm text-faint hover:text-red-600" data-action="<?= e(url('/settings/inbox/' . $b['id'])) ?>" data-method="DELETE" data-confirm="Remove this mailbox?" data-danger><?= icon('trash') ?></button>
        </li>
      <?php endforeach; ?>
      <?php if (!$boxes): ?><li class="px-4 py-8 text-center text-sm text-muted">No mailboxes yet.</li><?php endif; ?>
    </ul>
  </section>
  <form method="post" action="<?= e(url('/settings/inbox')) ?>" class="card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?>">
    <div class="card-header"><h2 class="card-title"><?= $e ? 'Edit mailbox' : 'Add mailbox' ?></h2></div>
    <div class="grid gap-4 p-4 sm:grid-cols-2">
      <div><label class="label" for="name">Name</label><input id="name" name="name" class="input" value="<?= e($e['name'] ?? '') ?>" placeholder="Support inbox"></div>
      <div><label class="label" for="purpose">Create</label><select id="purpose" name="purpose" class="select"><option value="tickets">Support tickets</option><option value="leads" <?= ($e['purpose'] ?? '') === 'leads' ? 'selected' : '' ?>>Leads</option></select></div>
      <div><label class="label" for="host">IMAP host</label><input id="host" name="host" class="input" required value="<?= e($e['host'] ?? '') ?>" placeholder="mail.yourdomain.com"></div>
      <div class="grid grid-cols-2 gap-3"><div><label class="label" for="port">Port</label><input id="port" name="port" type="number" class="input" value="<?= (int) ($e['port'] ?? 993) ?>"></div><div><label class="label" for="encryption">Security</label><select id="encryption" name="encryption" class="select"><option value="ssl">SSL/TLS (993)</option><option value="tls" <?= ($e['encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>STARTTLS (143)</option></select></div></div>
      <div><label class="label" for="username">Username / e-mail</label><input id="username" name="username" class="input" required autocomplete="off" value="<?= e($e['username'] ?? '') ?>"></div>
      <div><label class="label" for="password">Password</label><input id="password" name="password" type="password" class="input" autocomplete="new-password" placeholder="<?= $e ? '•••••••• saved — leave empty to keep' : '' ?>" <?= $e ? '' : 'required' ?>></div>
      <div><label class="label" for="folder">Folder</label><input id="folder" name="folder" class="input" value="<?= e($e['folder'] ?? 'INBOX') ?>"></div>
      <label class="mt-6 flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="is_active" value="1" <?= ($e['is_active'] ?? 1) ? 'checked' : '' ?>>Active</label>
    </div>
    <div class="flex justify-end gap-2 border-t border-theme p-4"><?php if ($e): ?><a class="btn btn-ghost" href="<?= e(url('/settings/inbox')) ?>">Cancel</a><?php endif; ?><button class="btn btn-primary" type="submit"><?= icon('save') ?>Save mailbox</button></div>
  </form>
  <p class="text-xs text-muted">Tip: cPanel mailboxes use your domain's mail server on port 993. Ticket replies you send use this address as Reply-To, so customer answers come back here and join the right ticket.</p>
</div>
</div>

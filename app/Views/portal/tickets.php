<?php defined('NX_ROOT') || exit; ?>
<div class="mb-4 flex items-center gap-3"><h1 class="flex-1 text-xl font-semibold tracking-tight">Support</h1></div>
<details class="card mb-4 p-4" <?= !$tickets ? 'open' : '' ?>>
  <summary class="cursor-pointer font-medium">New support ticket</summary>
  <form method="post" action="<?= e(url('/portal/tickets')) ?>" class="mt-4 space-y-3">
    <?= csrf_field() ?>
    <div><label class="label" for="subject">Subject</label><input id="subject" name="subject" class="input" required maxlength="190"></div>
    <div><label class="label" for="priority">Priority</label><select id="priority" name="priority" class="select"><option>Low</option><option selected>Medium</option><option>High</option><option>Urgent</option></select></div>
    <div><label class="label" for="description">How can we help?</label><textarea id="description" name="description" rows="5" class="textarea" required></textarea></div>
    <button class="btn btn-primary" type="submit"><?= icon('send') ?>Submit ticket</button>
  </form>
</details>
<section class="card overflow-x-auto">
  <?php if ($tickets): ?>
  <table class="data-table"><thead><tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Updated</th></tr></thead><tbody>
    <?php foreach ($tickets as $t): ?><tr class="cursor-pointer" data-href="<?= e(url('/portal/tickets/' . $t['id'])) ?>"><td class="font-medium"><?= e($t['number']) ?></td><td class="max-w-sm truncate"><?= e($t['subject']) ?></td><td><?= badge($t['status']) ?></td><td class="text-xs text-muted"><?= e(time_ago($t['updated_at'])) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p class="p-8 text-center text-sm text-muted">No tickets yet.</p><?php endif; ?>
</section>

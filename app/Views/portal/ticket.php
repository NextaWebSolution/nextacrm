<?php defined('NX_ROOT') || exit; ?>
<a href="<?= e(url('/portal/tickets')) ?>" class="btn btn-ghost btn-sm mb-3"><?= icon('arrow-left') ?>Support</a>
<div class="mb-4 flex flex-wrap items-center gap-2"><h1 class="flex-1 text-xl font-semibold tracking-tight"><?= e($t['subject']) ?></h1><?= badge($t['status']) ?><span class="text-sm text-muted"><?= e($t['number']) ?></span></div>
<section class="card space-y-4 p-4">
  <div class="rounded-lg border border-theme bg-surface-2 px-3.5 py-2.5"><p class="text-xs"><span class="font-medium"><?= e($t['requester_name'] ?: 'You') ?></span> <span class="text-faint"><?= e(fmt_datetime($t['created_at'])) ?></span></p><div class="mt-1 text-sm"><?= nl2br_safe($t['description']) ?></div></div>
  <?php foreach ($replies as $rp): ?>
    <div class="rounded-lg border px-3.5 py-2.5 <?= $rp['user_id'] ? 'border-primary-200 bg-primary-50/40 dark:border-primary-900 dark:bg-primary-950/20' : 'border-theme' ?>"><p class="text-xs"><span class="font-medium"><?= e($rp['author_name']) ?></span> <span class="text-faint"><?= $rp['user_id'] ? 'Support team · ' : '' ?><?= e(fmt_datetime($rp['created_at'])) ?></span></p><div class="mt-1 text-sm"><?= nl2br_safe($rp['body']) ?></div></div>
  <?php endforeach; ?>
</section>
<?php if ($t['status'] !== 'Closed'): ?>
<form method="post" action="<?= e(url('/portal/tickets/' . $t['id'] . '/reply')) ?>" class="card mt-4 p-4">
  <?= csrf_field() ?>
  <label class="label" for="body">Add a reply</label><textarea id="body" name="body" rows="4" class="textarea" required></textarea>
  <button class="btn btn-primary mt-3" type="submit"><?= icon('send') ?>Send reply</button>
</form>
<?php endif; ?>

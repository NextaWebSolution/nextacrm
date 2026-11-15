<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto max-w-3xl">
  <div class="mb-4 flex items-center justify-between"><h1 class="text-xl font-semibold tracking-tight">Inbox</h1>
    <button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/x/notifications/read')) ?>" data-body="{}" x-data @click="setTimeout(() => location.reload(), 400)">Mark all as read</button></div>
  <div class="card divide-theme">
    <?php if (!$items): ?><p class="px-6 py-14 text-center text-sm text-muted">No notifications yet. You'll be notified about assignments, reminders and automation messages.</p><?php endif; ?>
    <?php foreach ($items as $n): ?>
      <a href="<?= e($n['url'] ? url($n['url']) : '#') ?>" class="flex gap-3 px-4 py-3 hover:bg-surface-2">
        <span class="mt-1.5 size-2 shrink-0 rounded-full <?= $n['read_at'] ? 'bg-transparent' : 'bg-primary-500' ?>"></span>
        <span class="min-w-0 flex-1"><span class="block text-[13px] <?= $n['read_at'] ? '' : 'font-semibold' ?>"><?= e($n['title']) ?></span><?php if ($n['body']): ?><span class="block text-xs text-muted"><?= e(str_limit($n['body'], 160)) ?></span><?php endif; ?></span>
        <span class="shrink-0 text-xs text-faint"><?= e(time_ago($n['created_at'])) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

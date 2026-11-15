<?php defined('NX_ROOT') || exit;
if (!$a) return;
$iconMap = ['note' => 'message-square', 'call' => 'phone', 'meeting' => 'users', 'email' => 'mail', 'whatsapp' => 'smartphone', 'system' => 'activity'];
$isSystem = $a['type'] === 'system';
$canDelete = !$isSystem && ((int) $a['user_id'] === (int) user()['id'] || !empty(user()['is_admin']));
?>
<li class="group relative flex gap-3 pb-5" x-data="{ gone: false }" x-show="!gone">
  <span class="absolute left-[13px] top-8 bottom-0 w-px bg-[var(--border)]"></span>
  <span class="timeline-dot <?= $isSystem ? '' : 'text-primary-600 dark:text-primary-400' ?>"><?= icon($iconMap[$a['type']] ?? 'circle-dot', 'size-3.5') ?></span>
  <div class="min-w-0 flex-1 <?= $isSystem ? '' : 'rounded-lg border border-theme surface px-3.5 py-2.5' ?>">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
      <span class="font-medium text-[var(--text)]"><?= e($a['user']['name'] ?? 'System') ?></span>
      <span class="text-muted"><?= $isSystem ? 'updated this record' : e(strtolower(App\Services\ActivityService::TYPES[$a['type']] ?? 'note')) ?></span>
      <span class="text-faint" title="<?= e(fmt_datetime($a['occurred_at'])) ?>"><?= e(time_ago($a['occurred_at'])) ?></span>
      <?php if ($canDelete): ?><button type="button" class="ml-auto hidden text-faint hover:text-red-600 group-hover:inline-flex" title="Delete" @click="NX.confirm({message: 'Delete this entry?', danger: true}).then(ok => ok && NX.api(NX.url('/activities/<?= (int) $a['id'] ?>'), {method: 'DELETE'}).then(() => { gone = true; NX.toast('Removed'); }).catch(NX.fail))"><?= icon('trash', 'size-3.5') ?></button><?php endif; ?>
    </div>
    <?php if ($a['subject']): ?><p class="mt-1 text-[13px] font-medium"><?= e($a['subject']) ?></p><?php endif; ?>
    <div class="mt-1 text-[13px] <?= $isSystem ? 'text-muted' : '' ?> break-words"><?= nl2br_safe($a['body']) ?></div>
  </div>
</li>

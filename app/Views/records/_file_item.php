<?php defined('NX_ROOT') || exit; use App\Services\FileService; ?>
<li class="flex items-center gap-3 px-1 py-2" x-data="{ gone: false }" x-show="!gone">
  <span class="grid size-9 shrink-0 place-items-center rounded-lg border border-theme bg-surface-2 text-muted"><?= icon(FileService::iconFor($f['ext'] ?? ''), 'size-4') ?></span>
  <div class="min-w-0 flex-1">
    <a href="<?= e(url('/files/' . $f['id'] . (in_array($f['mime'], FileService::INLINE_MIME, true) ? '/view' : '/download'))) ?>" target="_blank" rel="noopener" class="block truncate text-[13px] font-medium hover:underline"><?= e($f['name']) ?></a>
    <p class="truncate text-xs text-faint"><?= e(FileService::humanSize((int) $f['size'])) ?> · <?= e($f['uploader'] ?? '') ?> · <?= e(time_ago($f['created_at'])) ?></p>
  </div>
  <?php if (in_array($f['entity'] ?? '', ['customers', 'projects', 'invoices', 'quotes', 'tickets', 'contracts'], true) && !empty($canDelete)): ?>
    <button type="button" class="btn btn-ghost btn-sm <?= !empty($f['is_public']) ? 'text-emerald-600' : 'text-faint' ?>" title="<?= !empty($f['is_public']) ? 'Shared in the client portal — click to stop sharing' : 'Share in the client portal' ?>" data-action="<?= e(url('/files/' . (int) $f['id'] . '/portal')) ?>"><?= icon('door-open', 'size-4') ?><span class="hidden sm:inline"><?= !empty($f['is_public']) ? 'Shared' : 'Share' ?></span></button>
  <?php endif; ?>
  <a href="<?= e(url('/files/' . $f['id'] . '/download')) ?>" class="btn btn-ghost btn-icon btn-sm" title="Download"><?= icon('download') ?></a>
  <?php if (!empty($canDelete)): ?><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" title="Delete" @click="NX.confirm({message: 'Delete this file permanently?', danger: true}).then(ok => ok && NX.api(NX.url('/files/<?= (int) $f['id'] ?>'), {method: 'DELETE'}).then(d => { gone = true; NX.handle(d); }).catch(NX.fail))"><?= icon('trash') ?></button><?php endif; ?>
</li>

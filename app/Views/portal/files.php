<?php defined('NX_ROOT') || exit; ?>
<h1 class="mb-4 text-xl font-semibold tracking-tight">Documents</h1>
<section class="card">
  <ul class="divide-theme">
    <?php foreach ($files as $f): ?><li class="flex items-center gap-3 px-4 py-3"><span class="text-faint"><?= icon(App\Services\FileService::iconFor($f['ext'])) ?></span><span class="min-w-0 flex-1 truncate"><?= e($f['name']) ?></span><span class="text-xs text-muted"><?= e(App\Services\FileService::humanSize((int) $f['size'])) ?></span><a class="btn btn-secondary btn-sm" href="<?= e(url('/portal/files/' . $f['id'])) ?>"><?= icon('download') ?>Download</a></li><?php endforeach; ?>
    <?php if (!$files): ?><li class="px-4 py-8 text-center text-sm text-muted">No documents have been shared with you yet.</li><?php endif; ?>
  </ul>
</section>

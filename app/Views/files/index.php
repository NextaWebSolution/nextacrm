<?php defined('NX_ROOT') || exit; use App\Services\FileService; ?>
<div class="mx-auto max-w-6xl" x-data="uploader(<?= ej(['url' => url('/files'), 'extra' => ['folder_id' => (string) ($folder['id'] ?? '')], 'reload' => true]) ?>)">
  <div class="mb-4 flex flex-wrap items-center gap-3">
    <div class="min-w-0 flex-1">
      <h1 class="text-xl font-semibold tracking-tight">Files</h1>
      <nav class="mt-1 flex flex-wrap items-center gap-1 text-sm text-muted"><a href="<?= e(url('/files')) ?>" class="hover:text-[var(--text)]">All files</a><?php foreach ($crumbs as $c): ?><span class="text-faint"><?= icon('chevron-right', 'size-3.5') ?></span><a class="hover:text-[var(--text)]" href="<?= e(url('/files', ['folder' => $c['id']])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?></nav>
    </div>
    <form method="get" action="<?= e(url('/files')) ?>" class="relative w-full sm:w-64"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint"><?= icon('search') ?></span><input name="q" value="<?= e($q) ?>" class="input pl-9" placeholder="Search all files…" type="search"></form>
    <?php if (can('files', 'create')): ?>
      <form method="post" action="<?= e(url('/files/folders')) ?>" data-ajax class="flex gap-2" x-data="{ o: false }">
        <?= csrf_field() ?><input type="hidden" name="parent_id" value="<?= (int) ($folder['id'] ?? 0) ?>">
        <input x-show="o" x-cloak name="name" class="input w-40" placeholder="Folder name" required>
        <button type="button" class="btn btn-secondary" x-show="!o" @click="o = true; $nextTick(() => $el.previousElementSibling.focus())"><?= icon('folder') ?>New folder</button>
        <button type="submit" class="btn btn-secondary" x-show="o" x-cloak>Create</button>
      </form>
      <button type="button" class="btn btn-primary" @click="pick()"><?= icon('upload') ?>Upload</button>
      <input type="file" x-ref="input" class="hidden" multiple @change="changed($event)">
    <?php endif; ?>
  </div>
  <div class="card" @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="drop($event)" :class="over && 'ring-2 ring-primary-400'">
    <template x-for="u in uploads" :key="u.name"><div class="flex items-center gap-3 border-b border-theme px-4 py-2 text-xs"><span class="truncate" x-text="u.name"></span><span class="progress w-32"><span :style="'width:' + u.pct + '%'"></span></span><span class="text-red-600" x-text="u.error"></span></div></template>
    <?php if (!$folders && !$files): ?>
      <div class="flex flex-col items-center px-6 py-16 text-center"><span class="text-faint"><?= icon('folder', 'size-8') ?></span><p class="mt-3 font-medium"><?= $q !== '' ? 'No files match your search' : 'This folder is empty' ?></p><p class="mt-1 text-sm text-muted">Drag files here or use Upload. Files attached to records are listed on each record.</p></div>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Name</th><th class="hidden sm:table-cell">Size</th><th class="hidden md:table-cell">Uploaded by</th><th class="hidden md:table-cell">Date</th><th class="w-24"></th></tr></thead>
      <tbody>
        <?php foreach ($folders as $f): ?><tr class="cursor-pointer" data-href="<?= e(url('/files', ['folder' => $f['id']])) ?>"><td><span class="inline-flex items-center gap-2.5 font-medium"><span class="text-amber-500"><?= icon('folder', 'size-4.5') ?></span><?= e($f['name']) ?></span></td><td class="hidden text-muted sm:table-cell"><?= (int) $f['file_count'] ?> files</td><td class="hidden md:table-cell"></td><td class="hidden text-muted md:table-cell"><?= e(fmt_date(substr((string) $f['created_at'], 0, 10))) ?></td><td></td></tr><?php endforeach; ?>
        <?php foreach ($files as $f): $inline = in_array($f['mime'], FileService::INLINE_MIME, true); ?>
          <tr x-data="{ gone: false }" x-show="!gone">
            <td class="max-w-[24rem]"><a href="<?= e(url('/files/' . $f['id'] . ($inline ? '/view' : '/download'))) ?>" target="_blank" rel="noopener" class="inline-flex min-w-0 items-center gap-2.5 font-medium hover:underline"><span class="text-faint"><?= icon(FileService::iconFor($f['ext']), 'size-4.5') ?></span><span class="truncate"><?= e($f['name']) ?></span></a>
              <?php if ($f['entity']): ?><a class="ml-7 block text-xs link" href="<?= e(url('/r/' . $f['entity'] . '/' . $f['record_id'])) ?>">on <?= e($f['entity']) ?> #<?= (int) $f['record_id'] ?></a><?php endif; ?></td>
            <td class="hidden text-muted sm:table-cell"><?= e(FileService::humanSize((int) $f['size'])) ?></td>
            <td class="hidden md:table-cell"><?= e($users[(int) $f['uploaded_by']]['name'] ?? '') ?></td>
            <td class="hidden text-muted md:table-cell"><?= e(time_ago($f['created_at'])) ?></td>
            <td class="text-right"><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/files/' . $f['id'] . '/download')) ?>" title="Download"><?= icon('download') ?></a>
              <?php if (can('files', 'delete') || (int) $f['uploaded_by'] === (int) user()['id']): ?><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" title="Delete" @click="NX.confirm({message: 'Delete this file permanently?', danger: true}).then(ok => ok && NX.api(NX.url('/files/<?= (int) $f['id'] ?>'), {method: 'DELETE'}).then(d => { gone = true; NX.handle(d); }).catch(NX.fail))"><?= icon('trash') ?></button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <p class="mt-3 text-xs text-faint"><?= (int) $usage['c'] ?> files · <?= e(FileService::humanSize((int) $usage['s'])) ?> used · stored on <?= e(App\Core\Storage\StorageManager::DRIVERS[App\Core\Storage\StorageManager::defaultDriver()]) ?></p>
</div>

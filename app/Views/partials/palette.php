<?php defined('NX_ROOT') || exit;
$actions = [];
$actions[] = ['label' => 'Go to Dashboard', 'url' => url('/'), 'icon' => 'layout-dashboard', 'hint' => 'G D'];
foreach ($nav as $items) {
    foreach ($items as $it) {
        $actions[] = ['label' => 'Go to ' . $it['label'], 'url' => $it['url'], 'icon' => $it['icon']];
    }
}
if (can('calendar', 'view')) { $actions[] = ['label' => 'Go to Calendar', 'url' => url('/calendar'), 'icon' => 'calendar', 'hint' => 'G C']; }
if (can('reports', 'view')) { $actions[] = ['label' => 'Go to Reports', 'url' => url('/reports'), 'icon' => 'chart-column']; }
if (can('files', 'view')) { $actions[] = ['label' => 'Go to Files', 'url' => url('/files'), 'icon' => 'folder']; }
foreach ($quickCreate as $qc) {
    $actions[] = ['label' => 'Create ' . strtolower($qc['label']), 'create' => $qc['entity'], 'icon' => 'plus'];
}
if (can('invoices', 'create')) { $actions[] = ['label' => 'Create invoice', 'url' => url('/documents/invoice/new'), 'icon' => 'plus']; }
if (can('quotes', 'create')) { $actions[] = ['label' => 'Create quotation', 'url' => url('/documents/quote/new'), 'icon' => 'plus']; }
$actions[] = ['label' => 'My profile', 'url' => url('/profile'), 'icon' => 'user'];
if (!empty(user()['is_admin'])) { $actions[] = ['label' => 'Settings', 'url' => url('/settings'), 'icon' => 'settings']; }
$icons = [];
foreach (array_merge(array_column($actions, 'icon'), ['target', 'building', 'contact', 'handshake', 'folder-kanban', 'square-check-big', 'life-buoy', 'receipt', 'file-text', 'briefcase', 'scale', 'book-open', 'package', 'credit-card', 'wallet', 'calendar-days', 'flag', 'umbrella', 'network', 'clock', 'file', 'undo-2', 'search']) as $ic) {
    $icons[$ic] = icon($ic, 'size-4');
}
?>
<div x-data="palette" data-actions="<?= ej($actions) ?>" data-icons="<?= ej($icons) ?>" @open-palette.window="show()" @keydown.escape.window="open && close()">
  <div x-cloak x-show="open" x-transition.opacity class="overlay" @click="close()"></div>
  <div x-cloak x-show="open" x-transition class="modal top-[8vh] w-[min(40rem,calc(100vw-1.5rem))] overflow-hidden p-0" role="dialog" aria-modal="true" aria-label="Search">
    <div class="flex items-center gap-2.5 border-b border-theme px-4">
      <span class="text-faint"><?= icon('search', 'size-5') ?></span>
      <input x-ref="input" type="text" class="h-13 w-full bg-transparent text-[15px] outline-none placeholder:text-[var(--faint)]" placeholder="Search leads, customers, deals, tasks, invoices… or type a command"
             x-model="q" @input.debounce.200ms="search()" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()" autocomplete="off" spellcheck="false" aria-autocomplete="list">
      <span class="text-faint" x-show="loading"><?= icon('loader-circle', 'size-4 animate-spin') ?></span>
      <span class="kbd">Esc</span>
    </div>
    <div class="max-h-[60vh] overflow-y-auto p-1.5" x-ref="list">
      <template x-if="!q && recent.length">
        <div>
          <div class="px-2.5 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-faint">Recent</div>
          <template x-for="(r, i) in recent" :key="'r' + i">
            <button type="button" class="menu-item" :class="hl === i && 'hl'" @mouseenter="hl = i" @click="q = r; search()"><span class="text-faint" x-html="icons['undo-2']"></span><span x-text="r"></span></button>
          </template>
        </div>
      </template>
      <template x-for="(item, i) in flat" :key="item.key">
        <div>
          <div x-show="item.header" class="px-2.5 pb-1 pt-2.5 text-[11px] font-semibold uppercase tracking-wider text-faint" x-text="item.header"></div>
          <button type="button" class="menu-item" :class="hl === i + recentOffset && 'hl'" @mouseenter="hl = i + recentOffset" @click="open1(item)">
            <span class="text-faint" x-html="icons[item.icon] || icons['search']"></span>
            <span class="min-w-0 flex-1"><span class="block truncate" x-text="item.title"></span><span class="block truncate text-xs text-muted" x-show="item.subtitle" x-text="item.subtitle"></span></span>
            <span x-show="item.status" class="badge badge-slate before:hidden" x-text="item.status"></span>
            <span x-show="item.hint" class="kbd" x-text="item.hint"></span>
          </button>
        </div>
      </template>
      <template x-if="q.length >= 2 && !loading && flat.length === 0">
        <div class="px-4 py-10 text-center text-sm text-muted">No results for “<span x-text="q"></span>”.</div>
      </template>
    </div>
    <div class="flex items-center gap-3 border-t border-theme px-4 py-2 text-[11px] text-faint">
      <span><span class="kbd">↑</span> <span class="kbd">↓</span> navigate</span><span><span class="kbd">Enter</span> open</span><span class="ml-auto">Results respect your permissions</span>
    </div>
  </div>
</div>

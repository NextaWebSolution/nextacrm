<?php defined('NX_ROOT') || exit; ?>
<!-- Toasts -->
<div class="pointer-events-none fixed bottom-20 right-3 z-[100] flex flex-col items-end gap-2 sm:bottom-5 sm:right-5" aria-live="polite">
  <template x-for="t in $store.nx.toasts" :key="t.id">
    <div class="toast pointer-events-auto" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-2 opacity-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-end="opacity-0">
      <span class="mt-0.5 shrink-0" :class="{ 'text-emerald-600': t.type === 'success', 'text-red-600': t.type === 'error', 'text-amber-600': t.type === 'warning', 'text-sky-600': t.type === 'info' }">
        <template x-if="t.type === 'success'"><?= icon('circle-check', 'size-4.5') ?></template>
        <template x-if="t.type === 'error'"><?= icon('circle-x', 'size-4.5') ?></template>
        <template x-if="t.type === 'warning'"><?= icon('triangle-exclamation-point', 'size-4.5') ?></template>
        <template x-if="t.type === 'info'"><?= icon('info', 'size-4.5') ?></template>
      </span>
      <span class="flex-1 break-words" x-text="t.message"></span>
      <button type="button" class="text-faint hover:text-[var(--text)]" @click="$store.nx.dismiss(t.id)" aria-label="Dismiss"><?= icon('x', 'size-4') ?></button>
    </div>
  </template>
</div>

<!-- Confirm dialog -->
<div x-data x-cloak x-show="$store.nx.confirm.open" @keydown.escape.window="$store.nx.confirm.open && $store.nx.answer(false)">
  <div class="overlay z-[85]" x-transition.opacity @click="$store.nx.answer(false)"></div>
  <div class="modal z-[90] p-5" role="alertdialog" aria-modal="true" x-transition>
    <div class="flex gap-3.5">
      <span class="grid size-10 shrink-0 place-items-center rounded-full" :class="$store.nx.confirm.danger ? 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' : 'bg-primary-100 text-primary-700'"><?= icon('triangle-exclamation-point', 'size-5') ?></span>
      <div class="min-w-0 flex-1">
        <h3 class="font-semibold" x-text="$store.nx.confirm.title"></h3>
        <p class="mt-1 text-sm text-muted" x-text="$store.nx.confirm.message"></p>
      </div>
    </div>
    <div class="mt-5 flex justify-end gap-2">
      <button type="button" class="btn btn-secondary" @click="$store.nx.answer(false)">Cancel</button>
      <button type="button" class="btn" :class="$store.nx.confirm.danger ? 'btn-danger' : 'btn-primary'" @click="$store.nx.answer(true)" x-text="$store.nx.confirm.ok" x-init="$watch('$store.nx.confirm.open', v => v && $nextTick(() => $el.focus()))"></button>
    </div>
  </div>
</div>

<!-- Record drawer (create / edit) -->
<div x-data="drawerForm" @open-form.window="openForm($event.detail)" @keydown.escape.window="open && close()">
  <div x-cloak x-show="open" x-transition.opacity class="overlay" @click="close()"></div>
  <div x-cloak x-show="open" class="drawer" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:leave="transition ease-in duration-150" x-transition:leave-end="translate-x-full" role="dialog" aria-modal="true">
    <template x-if="loading">
      <div class="space-y-4 p-5"><div class="skeleton h-7 w-48"></div><div class="skeleton h-9"></div><div class="skeleton h-9"></div><div class="skeleton h-9"></div><div class="skeleton h-24"></div></div>
    </template>
    <div x-ref="body" class="flex min-h-0 flex-1 flex-col" x-show="!loading"></div>
  </div>
</div>

<!-- Keyboard shortcuts -->
<div x-data="{ open: false }" @open-shortcuts.window="open = true" @keydown.escape.window="open = false">
  <div x-cloak x-show="open" class="overlay" @click="open = false"></div>
  <div x-cloak x-show="open" class="modal p-5" x-transition>
    <div class="mb-3 flex items-center justify-between"><h3 class="font-semibold">Keyboard shortcuts</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="open = false"><?= icon('x') ?></button></div>
    <dl class="grid grid-cols-[1fr_auto] gap-y-2 text-sm">
      <dt>Search / command palette</dt><dd><span class="kbd">Ctrl</span> <span class="kbd">K</span> or <span class="kbd">/</span></dd>
      <dt>Create new record (on a list)</dt><dd><span class="kbd">C</span></dd>
      <dt>Go to dashboard</dt><dd><span class="kbd">G</span> <span class="kbd">D</span></dd>
      <dt>Go to leads / deals / tasks</dt><dd><span class="kbd">G</span> <span class="kbd">L</span> · <span class="kbd">G</span> <span class="kbd">P</span> · <span class="kbd">G</span> <span class="kbd">T</span></dd>
      <dt>Go to calendar</dt><dd><span class="kbd">G</span> <span class="kbd">C</span></dd>
      <dt>Close dialogs</dt><dd><span class="kbd">Esc</span></dd>
      <dt>Show this help</dt><dd><span class="kbd">?</span></dd>
    </dl>
  </div>
</div>

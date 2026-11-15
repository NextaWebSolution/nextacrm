<?php defined('NX_ROOT') || exit; $canCreate = can('events', 'create'); ?>
<div class="mx-auto max-w-[1400px]" x-data="calendar(<?= ej(['weekStart' => (int) setting('app.week_start', 1), 'canCreate' => $canCreate]) ?>)" data-page-entity="events" data-list data-can-create="<?= $canCreate ? '1' : '0' ?>">
  <div class="mb-4 flex flex-wrap items-center gap-2">
    <h1 class="mr-2 text-xl font-semibold tracking-tight" x-text="title"></h1>
    <div class="flex items-center gap-1"><button type="button" class="btn btn-secondary btn-icon btn-sm" @click="shift(-1)" aria-label="Previous"><?= icon('chevron-left') ?></button><button type="button" class="btn btn-secondary btn-sm" @click="today()">Today</button><button type="button" class="btn btn-secondary btn-icon btn-sm" @click="shift(1)" aria-label="Next"><?= icon('chevron-right') ?></button></div>
    <span x-show="loading" class="text-faint"><?= icon('loader-circle', 'size-4 animate-spin') ?></span>
    <div class="ml-auto flex flex-wrap items-center gap-2">
      <div class="relative" x-data="{ o: false }"><button type="button" class="btn btn-secondary btn-sm" @click="o = !o"><?= icon('layers') ?>Layers</button>
        <div x-cloak x-show="o" @click.outside="o = false" class="menu right-0 mt-2 w-52">
          <?php foreach (['events' => 'Meetings & events', 'tasks' => 'Task due dates', 'leave' => 'Leave', 'invoices' => 'Invoice due dates', 'deals' => 'Deal close dates'] as $k => $l): ?><label class="menu-item cursor-pointer"><input type="checkbox" class="checkbox" :checked="layers.includes('<?= $k ?>')" @change="toggleLayer('<?= $k ?>')"><?= $l ?></label><?php endforeach; ?>
          <div class="menu-sep"></div><label class="menu-item cursor-pointer"><input type="checkbox" class="checkbox" x-model="mine" @change="load()">Only mine</label>
        </div></div>
      <div class="seg"><button type="button" :class="mode === 'month' && 'on'" @click="setMode('month')">Month</button><button type="button" :class="mode === 'week' && 'on'" @click="setMode('week')">Week</button><button type="button" :class="mode === 'agenda' && 'on'" @click="setMode('agenda')">Agenda</button></div>
      <?php if ($canCreate): ?><button type="button" class="btn btn-primary btn-sm" @click="$dispatch('open-form', { entity: 'events' })"><?= icon('plus') ?>Event</button><?php endif; ?>
    </div>
  </div>

  <div class="card overflow-hidden" x-show="mode === 'month'">
    <div class="cal-grid border-b border-theme bg-surface-2 text-center text-xs font-medium text-muted"><template x-for="w in weekdays"><div class="py-2" x-text="w"></div></template></div>
    <div class="cal-grid">
      <template x-for="d in days" :key="ymd(d)">
        <div class="cal-cell group" :class="!inMonth(d) && 'bg-surface-2/60'" @dblclick="newOn(d)">
          <div class="flex items-center justify-between">
            <span class="grid size-6 place-items-center rounded-full text-xs" :class="isToday(d) ? 'bg-primary-600 font-semibold text-white' : (inMonth(d) ? '' : 'text-faint')" x-text="d.getDate()"></span>
            <?php if ($canCreate): ?><button type="button" class="hidden size-5 place-items-center rounded text-faint hover:text-[var(--text)] group-hover:grid" @click="newOn(d)" aria-label="Add event"><?= icon('plus', 'size-3.5') ?></button><?php endif; ?>
          </div>
          <template x-for="i in itemsOn(d).slice(0, 4)" :key="i.id"><a :href="i.url" class="cal-event" :class="[color(i.color), i.done && 'line-through opacity-60']" :title="i.title + (i.meta ? ' — ' + i.meta : '')"><span x-show="time(i)" class="font-semibold" x-text="time(i) + ' '"></span><span x-text="i.title"></span></a></template>
          <p x-show="itemsOn(d).length > 4" class="mt-0.5 px-1 text-[11px] text-muted" x-text="'+' + (itemsOn(d).length - 4) + ' more'"></p>
        </div>
      </template>
    </div>
  </div>

  <div class="card overflow-hidden" x-show="mode === 'week'" x-cloak>
    <div class="grid grid-cols-1 divide-y divide-[var(--border)] md:grid-cols-7 md:divide-x md:divide-y-0">
      <template x-for="d in days" :key="ymd(d)">
        <div class="min-h-40 p-2">
          <p class="mb-2 text-xs font-medium" :class="isToday(d) ? 'text-primary-600' : 'text-muted'" x-text="d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })"></p>
          <template x-for="i in itemsOn(d)" :key="i.id"><a :href="i.url" class="mb-1 block rounded-md px-2 py-1.5 text-xs" :class="color(i.color)"><span class="block font-medium" x-text="(time(i) ? time(i) + ' · ' : '') + i.title"></span><span class="block opacity-75" x-text="i.meta"></span></a></template>
          <?php if ($canCreate): ?><button type="button" class="mt-1 text-xs text-faint hover:text-[var(--text)]" @click="newOn(d)">+ Add</button><?php endif; ?>
        </div>
      </template>
    </div>
  </div>

  <div x-show="mode === 'agenda'" x-cloak class="space-y-3">
    <template x-if="!agenda.length && !loading"><div class="card px-6 py-14 text-center text-sm text-muted">Nothing scheduled in the next 30 days.</div></template>
    <template x-for="g in agenda" :key="ymd(g.day)">
      <section class="card">
        <div class="border-b border-theme px-4 py-2 text-xs font-semibold" :class="isToday(g.day) ? 'text-primary-600' : 'text-muted'" x-text="g.day.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })"></div>
        <template x-for="i in g.items" :key="i.id"><a :href="i.url" class="flex items-center gap-3 border-b border-theme px-4 py-2.5 last:border-0 hover:bg-surface-2"><span class="w-12 text-xs tabular-nums text-muted" x-text="time(i) || 'All day'"></span><span class="h-8 w-1 rounded-full" :class="color(i.color)"></span><span class="min-w-0 flex-1"><span class="block truncate text-[13px] font-medium" x-text="i.title"></span><span class="block truncate text-xs text-muted" x-text="i.meta"></span></span></a></template>
      </section>
    </template>
  </div>
</div>

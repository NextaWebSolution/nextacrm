<?php defined('NX_ROOT') || exit; App\Core\View::share('extraScripts', ['js/chart.min.js']);
use App\Services\ReportBuilderService as RB;
?>
<div class="mx-auto max-w-6xl" x-data="reportBuilder(<?= ej(['previewUrl' => url('/reports/builder/preview'), 'saveUrl' => url('/reports/builder/' . (int) ($rep['id'] ?? 0)), 'entities' => $entities, 'cfg' => $cfg + ['filters' => []], 'name' => $rep['name'] ?? '', 'is_shared' => (bool) ($rep['is_shared'] ?? false), 'schedule' => $rep['schedule'] ?? 'none', 'recipients' => $rep['recipients'] ?? '']) ?>)">
  <div class="mb-5 flex flex-wrap items-center gap-3">
    <h1 class="flex-1 text-xl font-semibold tracking-tight"><?= $rep ? 'Edit report' : 'Report builder' ?></h1>
    <?php if ($rep): ?><button class="btn btn-danger-ghost" data-action="<?= e(url('/reports/custom/' . (int) $rep['id'])) ?>" data-method="DELETE" data-confirm="Delete this report?" data-danger><?= icon('trash') ?>Delete</button><?php endif; ?>
    <button class="btn btn-primary" @click="save()" :disabled="busy"><?= icon('save') ?>Save report</button>
  </div>
  <div class="grid gap-5 lg:grid-cols-[20rem_minmax(0,1fr)]">
    <section class="card space-y-4 p-4">
      <div><label class="label" for="rb-name">Report name</label><input id="rb-name" class="input" x-model="name" placeholder="Revenue by salesperson"></div>
      <div><label class="label" for="rb-entity">Module</label><select id="rb-entity" class="select" x-model="cfg.entity" @change="cfg.group_by = ''; cfg.metric_field = ''; cfg.filters = []; run()"><template x-for="(e, k) in entities" :key="k"><option :value="k" x-text="e.label" :selected="k === cfg.entity"></option></template></select></div>
      <div><label class="label" for="rb-group">Group by</label><select id="rb-group" class="select" x-model="cfg.group_by" @change="run()"><option value="">— No grouping (total) —</option><template x-for="(f, k) in ent().group" :key="k"><option :value="k" x-text="f.label" :selected="k === cfg.group_by"></option></template></select></div>
      <div x-show="isDate()"><label class="label" for="rb-bucket">Date bucket</label><select id="rb-bucket" class="select" x-model="cfg.bucket" @change="run()"><?php foreach (RB::BUCKETS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="label" for="rb-metric">Measure</label><select id="rb-metric" class="select" x-model="cfg.metric" @change="run()"><?php foreach (RB::METRICS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div x-show="cfg.metric !== 'count'"><label class="label" for="rb-mf">Of</label><select id="rb-mf" class="select" x-model="cfg.metric_field" @change="run()"><option value="">Choose…</option><template x-for="(l, k) in ent().numeric" :key="k"><option :value="k" x-text="l" :selected="k === cfg.metric_field"></option></template></select></div>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="label" for="rb-chart">Chart</label><select id="rb-chart" class="select" x-model="cfg.chart" @change="draw()"><?php foreach (RB::CHARTS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div><label class="label" for="rb-sort">Sort</label><select id="rb-sort" class="select" x-model="cfg.sort" @change="run()"><option value="value_desc">Highest first</option><option value="value_asc">Lowest first</option><option value="label_asc">A → Z</option></select></div>
      </div>
      <div>
        <div class="flex items-center justify-between"><span class="label mb-0">Filters</span><button type="button" class="btn btn-ghost btn-xs" @click="cfg.filters.push({ field: Object.keys(ent().group)[0] || '', op: 'eq', value: '' })"><?= icon('plus', 'size-3') ?>Add</button></div>
        <template x-for="(f, i) in cfg.filters" :key="i">
          <div class="mt-2 grid grid-cols-[1fr_auto] gap-1 rounded-lg border border-theme p-2">
            <select class="select input-sm" x-model="f.field"><template x-for="(g, k) in allFields()" :key="k"><option :value="k" x-text="g" :selected="k === f.field"></option></template></select>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="cfg.filters.splice(i, 1); run()" aria-label="Remove filter"><?= icon('x', 'size-3.5') ?></button>
            <select class="select input-sm" x-model="f.op"><option value="eq">is</option><option value="neq">is not</option><option value="contains">contains</option><option value="gt">greater than</option><option value="lt">less than</option><option value="this_month">this month</option><option value="last_days">in last N days</option><option value="not_empty">is set</option><option value="empty">is empty</option></select>
            <span></span>
            <input class="input input-sm" x-model="f.value" @change="run()" placeholder="Value">
          </div>
        </template>
      </div>
      <div class="border-t border-theme pt-4">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" x-model="is_shared">Share with everyone in this company</label>
        <div class="mt-3"><label class="label" for="rb-sch">E-mail this report</label><select id="rb-sch" class="select" x-model="schedule"><option value="none">Never</option><option value="daily">Every day</option><option value="weekly">Every Monday</option><option value="monthly">1st of every month</option></select></div>
        <div class="mt-2" x-show="schedule !== 'none'"><label class="label" for="rb-rcpt">Recipients (comma-separated)</label><input id="rb-rcpt" class="input" x-model="recipients" placeholder="ceo@company.com, sales@company.com"></div>
      </div>
    </section>
    <section class="card min-w-0">
      <div class="card-header"><h2 class="card-title" x-text="res.metric_label || 'Preview'"></h2><span class="text-xs text-muted" x-show="res.rows" x-text="'Total ' + fmt(res.total)"></span></div>
      <div class="h-72 p-3" x-show="cfg.chart !== 'none' && res.rows && res.rows.length"><canvas x-ref="canvas" role="img" aria-label="Report chart"></canvas></div>
      <p class="px-4 py-2 text-sm text-red-600" x-show="error" x-text="error"></p>
      <div class="overflow-x-auto border-t border-theme"><table class="data-table"><thead><tr><th x-text="res.group_label || 'Group'"></th><th class="text-right" x-text="res.metric_label || 'Value'"></th></tr></thead>
        <tbody><template x-for="row in (res.rows || [])" :key="row.label"><tr><td x-text="row.label"></td><td class="text-right tabular-nums" x-text="fmt(row.value)"></td></tr></template></tbody></table></div>
    </section>
  </div>
</div>

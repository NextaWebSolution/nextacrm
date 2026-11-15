<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<section class="card" x-data="pipelineEditor(<?= ej(['pipelines' => array_map(fn ($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'stages' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'probability' => (int) $s['probability'], 'color' => $s['color'], 'is_won' => (int) $s['is_won'], 'is_lost' => (int) $s['is_lost']], $p['stages'])], $pipelines)]) ?>)">
  <div class="card-header"><h2 class="card-title">Deal pipelines</h2><div class="flex gap-2"><button type="button" class="btn btn-secondary btn-sm" @click="addPipeline()"><?= icon('plus') ?>Pipeline</button><button type="button" class="btn btn-primary btn-sm" @click="save()" :disabled="saving">Save pipelines</button></div></div>
  <div class="space-y-4 p-4">
    <template x-for="(p, pi) in pipelines" :key="pi">
      <div class="rounded-xl border border-theme p-3">
        <div class="mb-2 flex items-center gap-2"><input class="input input-sm max-w-xs font-medium" x-model="p.name"><span class="text-xs text-faint" x-show="pi === 0">Default</span>
          <button type="button" class="btn btn-ghost btn-xs ml-auto" @click="move(pipelines, pi, -1)" x-show="pi > 0">Make earlier</button>
          <button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" @click="pipelines.splice(pi, 1)" x-show="pipelines.length > 1" aria-label="Remove pipeline"><?= icon('trash', 'size-3.5') ?></button></div>
        <div class="space-y-1.5">
          <template x-for="(s, si) in p.stages" :key="si">
            <div class="grid grid-cols-[1fr_5rem_6.5rem_auto] items-center gap-2 sm:grid-cols-[1fr_5.5rem_7rem_6rem_auto]">
              <input class="input input-sm" x-model="s.name" aria-label="Stage name">
              <div class="relative"><input type="number" min="0" max="100" class="input input-sm pr-6" x-model.number="s.probability" aria-label="Probability"><span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-faint">%</span></div>
              <select class="select input-sm" x-model="s.color"><template x-for="c in colors"><option :value="c" x-text="c"></option></template></select>
              <select class="select input-sm hidden sm:block" :value="s.is_won ? 'won' : (s.is_lost ? 'lost' : 'open')" @change="s.is_won = $event.target.value === 'won' ? 1 : 0; s.is_lost = $event.target.value === 'lost' ? 1 : 0"><option value="open">Open</option><option value="won">Won</option><option value="lost">Lost</option></select>
              <div class="flex"><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="move(p.stages, si, -1)" aria-label="Up"><?= icon('arrow-up', 'size-3.5') ?></button><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" @click="p.stages.splice(si, 1)" aria-label="Remove"><?= icon('x', 'size-3.5') ?></button></div>
            </div>
          </template>
        </div>
        <button type="button" class="btn btn-ghost btn-xs mt-2" @click="addStage(p)"><?= icon('plus', 'size-3.5') ?>Add stage</button>
      </div>
    </template>
  </div>
</section>

<form method="post" action="<?= e(url('/settings/picklists')) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">Dropdown lists</h2><span class="text-xs text-faint">One value per line</span></div>
  <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($picklists as $k => $p): ?><div><label class="label" for="pl_<?= e($k) ?>"><?= e($p['label']) ?></label><textarea id="pl_<?= e($k) ?>" name="<?= e($k) ?>" rows="6" class="textarea text-[13px]"><?= e(implode("\n", $p['values'])) ?></textarea></div><?php endforeach; ?>
  </div>
  <div class="flex items-center justify-between border-t border-theme px-5 py-3"><p class="text-xs text-faint">Statuses with built-in behaviour (Done, Resolved, Closed, Converted) are kept automatically.</p><button class="btn btn-primary" type="submit">Save lists</button></div>
</form>

<section class="card" x-data="rowsEditor(<?= ej(['rows' => array_map(fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'rate' => (float) $t['rate'], 'is_default' => (int) $t['is_default']], $taxes), 'blank' => ['id' => null, 'name' => '', 'rate' => 0, 'is_default' => 0], 'key' => 'taxes', 'url' => url('/settings/taxes')]) ?>)">
  <div class="card-header"><h2 class="card-title">Taxes</h2><div class="flex gap-2"><button type="button" class="btn btn-secondary btn-sm" @click="add()"><?= icon('plus') ?>Tax</button><button type="button" class="btn btn-primary btn-sm" @click="save()" :disabled="saving">Save taxes</button></div></div>
  <div class="space-y-2 p-4">
    <template x-for="(t, i) in rows" :key="i"><div class="flex items-center gap-2"><input class="input input-sm flex-1" x-model="t.name" placeholder="e.g. GST 18%"><div class="relative w-28"><input type="number" step="0.001" class="input input-sm pr-6" x-model.number="t.rate"><span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-faint">%</span></div><label class="flex items-center gap-1.5 text-xs text-muted"><input type="radio" name="tax_default" class="checkbox" :checked="t.is_default" @change="rows.forEach(r => r.is_default = 0); t.is_default = 1">Default</label><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" @click="remove(i)"><?= icon('x', 'size-3.5') ?></button></div></template>
  </div>
</section>

<form method="post" action="<?= e(url('/settings/currencies')) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">Currencies &amp; exchange rates</h2><span class="text-xs text-faint">Rates relative to INR = 1</span></div>
  <div class="max-h-96 overflow-y-auto"><table class="data-table"><thead><tr><th>Code</th><th>Name</th><th>Rate</th><th>Enabled</th></tr></thead><tbody>
    <?php foreach ($currencies as $c): ?><tr><td class="font-medium"><?= e($c['code']) ?></td><td><?= e($c['name']) ?></td><td><input type="number" step="0.000001" min="0" class="input input-sm w-32" name="currencies[<?= e($c['code']) ?>][rate]" value="<?= e((string) (float) $c['rate']) ?>"></td><td><input type="checkbox" class="checkbox" name="currencies[<?= e($c['code']) ?>][active]" value="1" <?= $c['is_active'] ? 'checked' : '' ?>></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="flex flex-wrap items-end gap-2 border-t border-theme p-4"><div><label class="label">Add code</label><input name="new_code" maxlength="3" class="input input-sm w-20 uppercase" placeholder="KES"></div><div><label class="label">Name</label><input name="new_name" class="input input-sm w-44"></div><div><label class="label">Rate</label><input name="new_rate" type="number" step="0.000001" class="input input-sm w-28"></div><button class="btn btn-primary btn-sm ml-auto" type="submit">Save currencies</button></div>
</form>

<form method="post" action="<?= e(url('/settings/sla')) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">Support SLA policies</h2><span class="text-xs text-faint">Hours from ticket creation</span></div>
  <div class="grid gap-3 p-5 sm:grid-cols-4">
    <?php foreach (['Urgent', 'High', 'Medium', 'Low'] as $p): ?><div class="rounded-lg border border-theme p-3"><p class="text-sm font-medium"><?= $p ?></p><label class="label mt-2">First response (h)</label><input type="number" min="1" name="sla_<?= $p ?>_response" class="input input-sm" value="<?= (int) ($sla[$p]['response'] ?? 8) ?>"><label class="label mt-2">Resolution (h)</label><input type="number" min="1" name="sla_<?= $p ?>_resolution" class="input input-sm" value="<?= (int) ($sla[$p]['resolution'] ?? 72) ?>"></div><?php endforeach; ?>
  </div>
  <div class="flex justify-end border-t border-theme px-5 py-3"><button class="btn btn-primary" type="submit">Save SLA</button></div>
</form>

<section class="card" x-data="rowsEditor(<?= ej(['rows' => $scoring, 'blank' => ['field' => 'email', 'op' => 'filled', 'value' => '', 'points' => 10], 'key' => 'rules', 'url' => url('/settings/scoring')]) ?>)">
  <div class="card-header"><h2 class="card-title">Lead scoring rules</h2><div class="flex gap-2"><button type="button" class="btn btn-secondary btn-sm" @click="add()"><?= icon('plus') ?>Rule</button><button type="button" class="btn btn-primary btn-sm" @click="save()" :disabled="saving">Save rules</button></div></div>
  <div class="space-y-2 p-4">
    <template x-for="(r, i) in rows" :key="i"><div class="flex flex-wrap items-center gap-2 text-sm"><span class="text-muted">If</span>
      <select class="select input-sm w-40" x-model="r.field"><?php foreach ($leadFields as $n => $f): if (!empty($f['system'])) continue; ?><option value="<?= e($n) ?>"><?= e($f['label']) ?></option><?php endforeach; ?></select>
      <select class="select input-sm w-32" x-model="r.op"><option value="filled">is filled</option><option value="eq">equals</option><option value="gt">greater than</option><option value="contains">contains</option></select>
      <input class="input input-sm w-36" x-model="r.value" x-show="r.op !== 'filled'" placeholder="Value"><span class="text-muted">add</span><input type="number" class="input input-sm w-20" x-model.number="r.points"><span class="text-muted">points</span>
      <button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" @click="remove(i)"><?= icon('x', 'size-3.5') ?></button></div></template>
    <p class="text-xs text-faint">Scores are recalculated whenever a lead is saved (0–100).</p>
  </div>
</section>
</div></div>

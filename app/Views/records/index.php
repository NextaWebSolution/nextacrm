<?php defined('NX_ROOT') || exit;
use App\Core\View;
use App\Services\EntityRegistry;
use App\Services\LookupService;
$fieldsMeta = [];
foreach (EntityRegistry::visibleFields($def) as $n => $f) {
    if ($f['type'] === 'hidden') continue;
    if (!empty($f['filter']) || !empty($f['list']) || in_array($f['type'], ['date', 'datetime', 'select', 'user', 'money', 'bool'], true)) {
        $fieldsMeta[$n] = ['label' => $f['label'], 'type' => $f['type'], 'options' => LookupService::fieldOptions($f)];
    }
}
$allCols = array_filter(EntityRegistry::visibleFields($def), fn ($f) => $f['type'] !== 'hidden');
$isDoc = !empty($def['document']);
$canCreate = can($def['module'], 'create');
$scope = (string) (App\Core\App::request()->query['scope'] ?? '');
$total = $viewMode === 'board' ? $boardTotal : $result['total'];
$cfg = [
    'entity' => $entity, 'view' => $viewMode, 'q' => $params['q'], 'filters' => array_map(fn ($f) => ['field' => $f['field'], 'op' => $f['op'], 'value' => is_array($f['value']) ? implode(',', $f['value']) : $f['value'], 'value2' => $f['value2'] ?? ''], array_values(array_filter($params['filters'], fn ($f) => $f['op'] !== 'me' || $f['field'] !== 'assigned_to' || $scope !== 'mine'))),
    'sort' => $params['sort'], 'dir' => $params['dir'], 'page' => $params['page'], 'scope' => $scope, 'pipeline' => (string) ($pipelineId ?? ''),
    'fields' => $fieldsMeta, 'columns' => $columns, 'total' => $total, 'canShare' => !empty(user()['is_admin']) || can($def['module'], 'manage'),
];
$currentQuery = $_SERVER['QUERY_STRING'] ?? '';
?>
<div class="mx-auto max-w-[1600px]" x-data="recordList(<?= ej($cfg) ?>)" data-page-entity="<?= e($entity) ?>" data-list data-can-create="<?= $canCreate && !$isDoc ? '1' : '0' ?>">
  <!-- Header -->
  <div class="mb-4 flex flex-wrap items-center gap-3">
    <div class="flex min-w-0 items-center gap-3">
      <span class="hidden size-9 place-items-center rounded-lg border border-theme surface text-muted sm:grid"><?= icon($def['icon'], 'size-4.5') ?></span>
      <div><h1 class="text-lg font-semibold tracking-tight sm:text-xl"><?= e($def['label']) ?></h1><p class="text-xs text-muted"><span x-text="total.toLocaleString()"><?= (int) $total ?></span> records</p></div>
    </div>
    <div class="ml-auto flex items-center gap-2">
      <?php if (can($def['module'], 'import')): ?><a href="<?= e(url('/r/' . $entity . '/import')) ?>" class="btn btn-secondary hidden sm:inline-flex"><?= icon('upload') ?>Import</a><?php endif; ?>
      <?php if (can($def['module'], 'export')): ?>
      <div class="relative" x-data="{ o: false }">
        <button type="button" class="btn btn-secondary" @click="o = !o"><?= icon('download') ?><span class="hidden sm:inline">Export</span></button>
        <div x-cloak x-show="o" @click.outside="o = false" class="menu right-0 mt-2 w-52">
          <a class="menu-item" :href="exportUrl('csv')" @click="o = false"><?= icon('file-text', 'size-4 text-faint') ?>CSV <span class="ml-auto text-xs text-faint" x-text="selected.length ? selected.length + ' selected' : 'current view'"></span></a>
          <a class="menu-item" :href="exportUrl('xlsx')" @click="o = false"><?= icon('file-spreadsheet', 'size-4 text-faint') ?>Excel (.xlsx)</a>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($canCreate): ?>
        <?php if ($isDoc): ?><a href="<?= e(url('/documents/' . $def['document'] . '/new')) ?>" class="btn btn-primary"><?= icon('plus') ?>New <?= e(strtolower($def['singular'])) ?></a>
        <?php else: ?><button type="button" class="btn btn-primary" @click="create()"><?= icon('plus') ?>New <span class="hidden sm:inline"><?= e(strtolower($def['singular'])) ?></span></button><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Views -->
  <div class="tabs mb-3">
    <a href="<?= e(url('/r/' . $entity, $viewMode !== 'list' ? ['view' => $viewMode] : [])) ?>" class="tab <?= $currentQuery === '' || $currentQuery === 'view=' . $viewMode ? 'active' : '' ?>">All</a>
    <?php if ($svc->hasAssigned()): ?><a href="<?= e(url('/r/' . $entity, ['scope' => 'mine', 'view' => $viewMode])) ?>" class="tab <?= $scope === 'mine' && count($params['filters']) === 1 ? 'active' : '' ?>"><?= icon('user', 'size-3.5') ?>Mine</a><?php endif; ?>
    <?php foreach ($savedViews as $sv): $q = http_build_query(json_decode((string) $sv['config'], true) ?: []); ?>
      <span class="group inline-flex items-center"><a href="<?= e(url('/r/' . $entity) . '?' . $q) ?>" class="tab <?= $currentQuery === $q ? 'active' : '' ?>"><?= $sv['is_shared'] ? icon('users', 'size-3.5') : '' ?><?= e($sv['name']) ?></a>
      <?php if ((int) $sv['user_id'] === (int) user()['id'] || !empty(user()['is_admin'])): ?><button type="button" class="hidden size-5 items-center justify-center rounded text-faint hover:text-red-600 group-hover:inline-flex" @click="deleteView(<?= (int) $sv['id'] ?>)" aria-label="Delete view"><?= icon('x', 'size-3') ?></button><?php endif; ?></span>
    <?php endforeach; ?>
    <button type="button" class="tab text-faint" @click="saveView()"><?= icon('plus', 'size-3.5') ?>Save view</button>
  </div>

  <!-- Toolbar -->
  <div class="mb-3 flex flex-wrap items-center gap-2">
    <div class="relative w-full sm:w-72">
      <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint"><?= icon('search') ?></span>
      <input type="search" class="input pl-9" placeholder="Search <?= e(strtolower($def['label'])) ?>…" x-model="q" @input.debounce.300ms="doSearch()" aria-label="Search">
    </div>
    <div class="relative">
      <button type="button" class="btn btn-secondary" @click="filterOpen = !filterOpen"><?= icon('funnel') ?>Filter<span x-show="filters.length" class="badge badge-indigo before:hidden" x-text="filters.length"></span></button>
      <div x-cloak x-show="filterOpen" @click.outside="filterOpen = false" class="menu left-0 mt-2 w-[min(22rem,calc(100vw-1.5rem))] space-y-2.5 p-3">
        <div><label class="label">Field</label><select class="select input-sm" x-model="nf.field" @change="pickField()"><option value="">Choose a field…</option><template x-for="(m, k) in fields" :key="k"><option :value="k" x-text="m.label"></option></template></select></div>
        <template x-if="nf.field">
          <div class="space-y-2.5">
            <div><label class="label">Condition</label><select class="select input-sm" x-model="nf.op"><template x-for="o in opsFor(nf.field)" :key="o[0]"><option :value="o[0]" x-text="o[1]"></option></template></select></div>
            <template x-if="needsValue(nf.op) && fieldMeta(nf.field).options.length && nf.op !== 'in'">
              <div><label class="label">Value</label><select class="select input-sm" x-model="nf.value"><option value="">Choose…</option><template x-for="o in fieldMeta(nf.field).options" :key="o.value"><option :value="o.value" x-text="(o.group ? o.group + ' · ' : '') + o.label"></option></template></select></div>
            </template>
            <template x-if="needsValue(nf.op) && nf.op === 'in'">
              <div><label class="label">Values</label><select class="select input-sm h-28" multiple x-model="nf.value"><template x-for="o in fieldMeta(nf.field).options" :key="o.value"><option :value="o.value" x-text="o.label"></option></template></select></div>
            </template>
            <template x-if="needsValue(nf.op) && !fieldMeta(nf.field).options.length">
              <div class="grid gap-2" :class="nf.op === 'between' && 'grid-cols-2'"><input class="input input-sm" :type="['last_days','next_days'].includes(nf.op) ? 'number' : inputType(nf.field)" x-model="nf.value" placeholder="Value" @keydown.enter.prevent="addFilter()"><input x-show="nf.op === 'between'" class="input input-sm" :type="inputType(nf.field)" x-model="nf.value2" placeholder="and"></div>
            </template>
            <div class="flex justify-end gap-2 pt-1"><button type="button" class="btn btn-ghost btn-sm" @click="filterOpen = false">Cancel</button><button type="button" class="btn btn-primary btn-sm" @click="addFilter()">Apply filter</button></div>
          </div>
        </template>
      </div>
    </div>
    <template x-for="(f, i) in filters" :key="i">
      <span class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-theme surface pl-2.5 pr-1 text-xs"><span x-text="chipText(f)"></span><button type="button" class="grid size-5 place-items-center rounded text-faint hover:text-[var(--text)]" @click="removeFilter(i)" aria-label="Remove filter"><?= icon('x', 'size-3') ?></button></span>
    </template>
    <button type="button" class="btn btn-ghost btn-sm" x-show="filters.length || q" x-cloak @click="clearAll()">Clear</button>

    <div class="ml-auto flex items-center gap-2">
      <span x-show="loading" x-cloak class="text-faint"><?= icon('loader-circle', 'size-4 animate-spin') ?></span>
      <?php if (!empty($pipelineId) && $viewMode === 'board'): ?>
        <select class="select input-sm w-auto" x-model="pipeline" @change="setPipeline(pipeline)" aria-label="Pipeline">
          <?php foreach (LookupService::pipelines() as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <?php if ($viewMode === 'list'): ?>
      <div class="relative">
        <button type="button" class="btn btn-secondary btn-icon" @click="colsOpen = !colsOpen" title="Columns" aria-label="Choose columns"><?= icon('columns-3') ?></button>
        <div x-cloak x-show="colsOpen" @click.outside="colsOpen = false" class="menu right-0 mt-2 w-64 p-2">
          <div class="max-h-72 overflow-y-auto">
            <?php foreach ($allCols as $n => $f): ?>
              <label class="menu-item cursor-pointer"><input type="checkbox" class="checkbox" :checked="columns.includes('<?= e($n) ?>')" @change="toggleCol('<?= e($n) ?>')"><?= e($f['label']) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 flex justify-end border-t border-theme pt-2"><button type="button" class="btn btn-primary btn-sm" @click="saveCols()">Apply</button></div>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!empty($def['board'])): ?>
      <div class="seg" role="tablist" aria-label="Layout">
        <button type="button" :class="view === 'list' && 'on'" @click="setView('list')"><?= icon('list', 'size-3.5') ?><span class="hidden sm:inline">List</span></button>
        <button type="button" :class="view === 'board' && 'on'" @click="setView('board')"><?= icon('kanban', 'size-3.5') ?><span class="hidden sm:inline">Board</span></button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Bulk bar -->
  <div x-cloak x-show="selected.length" x-transition class="sticky top-16 z-20 mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-primary-200 bg-primary-50 px-3 py-2 text-sm dark:border-primary-900/50 dark:bg-primary-900/20">
    <span class="font-medium"><span x-text="selected.length"></span> selected</span>
    <?php if (can($def['module'], 'edit')): ?>
    <select class="select input-sm w-auto" x-model="bulkField" aria-label="Field to update">
      <option value="">Update field…</option>
      <?php foreach (EntityRegistry::visibleFields($def) as $n => $f): if (empty($f['readonly']) && empty($f['system']) && in_array($f['type'], ['select', 'user', 'date', 'bool', 'stage', 'tags', 'text'], true)): ?>
        <option value="<?= e($n) ?>"><?= e($f['label']) ?></option>
      <?php endif; endforeach; ?>
    </select>
    <template x-if="bulkField && fieldMeta(bulkField).options.length"><select class="select input-sm w-auto" x-model="bulkValue"><option value="">—</option><template x-for="o in fieldMeta(bulkField).options" :key="o.value"><option :value="o.value" x-text="o.label"></option></template></select></template>
    <template x-if="bulkField && !fieldMeta(bulkField).options.length"><input class="input input-sm w-40" :type="fieldMeta(bulkField).type === 'date' ? 'date' : 'text'" x-model="bulkValue"></template>
    <button type="button" class="btn btn-secondary btn-sm" x-show="bulkField" @click="bulk('update')">Apply</button>
    <?php endif; ?>
    <?php if (can($def['module'], 'delete')): ?><button type="button" class="btn btn-danger-ghost btn-sm ml-auto" @click="bulk('delete')"><?= icon('trash') ?>Delete</button><?php endif; ?>
    <button type="button" class="btn btn-ghost btn-sm" @click="selected = []">Clear selection</button>
  </div>

  <div x-ref="results" :class="loading && 'opacity-60 transition-opacity'">
    <?php if ($viewMode === 'board'): ?>
      <?= View::partial('records/_board', compact('svc', 'entity', 'boardColumns', 'boardCards', 'boardCounts', 'boardField', 'pipelineId', 'boardTotal')) ?>
    <?php else: ?>
      <?= View::partial('records/_table', compact('svc', 'entity', 'result', 'columns', 'params')) ?>
    <?php endif; ?>
  </div>
</div>

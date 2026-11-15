<?php defined('NX_ROOT') || exit;
/** @var array $f field definition, mixed $value, ?string $display label for relations, string $id */
use App\Services\EntityRegistry;
use App\Services\LookupService;
$name = $f['name'];
$id = 'f_' . $name;
$req = !empty($f['required']) ? 'required' : '';
$val = $value ?? '';
switch ($f['type']):
    case 'textarea': ?>
      <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" class="textarea" rows="<?= (int) ($f['rows'] ?? 3) ?>" <?= $req ?>><?= e($val) ?></textarea>
    <?php break;
    case 'email': case 'url': case 'text': case 'phone': ?>
      <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= $f['type'] === 'phone' ? 'tel' : ($f['type'] === 'text' ? 'text' : $f['type']) ?>" class="input" value="<?= e($val) ?>" <?= $req ?> maxlength="<?= $f['type'] === 'url' ? 490 : 190 ?>" <?= $f['type'] === 'email' ? 'autocomplete="email"' : '' ?>>
    <?php break;
    case 'number': case 'decimal': case 'money': case 'percent': ?>
      <div class="relative">
        <?php if ($f['type'] === 'money'): ?><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-xs text-faint"><?= e(trim(currency_symbol(App\Services\CompanyService::currentCurrency()))) ?></span><?php endif; ?>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="number" inputmode="decimal" step="<?= $f['type'] === 'number' || $f['type'] === 'percent' ? '1' : '0.01' ?>" <?= $f['type'] === 'percent' ? 'min="0" max="100"' : '' ?> class="input <?= $f['type'] === 'money' ? 'pl-11' : '' ?>" value="<?= e($val) ?>" <?= $req ?>>
      </div>
    <?php break;
    case 'duration': ?>
      <input id="<?= e($id) ?>" name="<?= e($name) ?>" class="input" placeholder="e.g. 1:30, 1.5h or 90m" value="<?= $val !== '' ? e(intdiv((int) $val, 60) . ':' . str_pad((string) ((int) $val % 60), 2, '0', STR_PAD_LEFT)) : '' ?>" <?= $req ?>>
    <?php break;
    case 'date': ?>
      <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="date" class="input" value="<?= e(substr((string) $val, 0, 10)) ?>" <?= $req ?>>
    <?php break;
    case 'datetime': ?>
      <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="datetime-local" class="input" value="<?= e(strlen((string) $val) === 16 && str_contains((string) $val, 'T') ? $val : to_local_input((string) $val)) ?>" <?= $req ?>>
    <?php break;
    case 'bool': ?>
      <input type="hidden" name="_present_<?= e($name) ?>" value="1">
      <label class="inline-flex h-9 cursor-pointer items-center gap-2.5 text-sm"><input id="<?= e($id) ?>" type="checkbox" name="<?= e($name) ?>" value="1" class="checkbox" <?= $val ? 'checked' : '' ?>><span class="text-muted">Yes</span></label>
    <?php break;
    case 'multiselect':
      $sel = array_map('trim', explode(',', (string) $val)); ?>
      <div class="flex flex-wrap gap-x-4 gap-y-1.5 rounded-lg border border-theme p-2.5">
        <?php foreach (EntityRegistry::options($f) as $k => $l): ?>
          <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="<?= e($name) ?>[]" value="<?= e($k) ?>" <?= in_array((string) $k, $sel, true) ? 'checked' : '' ?>><?= e($l) ?></label>
        <?php endforeach; ?>
      </div>
    <?php break;
    case 'tags': ?>
      <input id="<?= e($id) ?>" name="<?= e($name) ?>" class="input" value="<?= e($val) ?>" placeholder="Comma-separated, e.g. website, priority">
    <?php break;
    case 'relation':
      $where = !empty($f['depends']) ? [$f['depends']] : []; ?>
      <div class="relative" x-data="relationPicker(<?= ej(['entity' => $f['relation'], 'value' => (string) $val, 'label' => (string) ($display ?? ''), 'where' => $where]) ?>)" @click.outside="open = false">
        <input type="hidden" name="<?= e($name) ?>" x-ref="hidden" :value="value">
        <div class="relative">
          <input id="<?= e($id) ?>" type="text" class="input pr-16" :placeholder="label || 'Search…'" :class="label && 'placeholder:text-[var(--text)]'" x-model="q" @focus="focus()" @input.debounce.200ms="open = true; fetch()" @keydown="key($event)" autocomplete="off" role="combobox" :aria-expanded="open">
          <div class="absolute inset-y-0 right-1.5 flex items-center gap-0.5">
            <button type="button" class="btn btn-ghost btn-xs px-1" x-show="value" @click="clear()" aria-label="Clear"><?= icon('x', 'size-3.5') ?></button>
            <span class="pointer-events-none px-1 text-faint"><?= icon('chevrons-up-down', 'size-3.5') ?></span>
          </div>
        </div>
        <div x-cloak x-show="open" class="menu left-0 right-0 mt-1 max-h-64 overflow-y-auto">
          <template x-if="loading && !items.length"><div class="p-2"><div class="skeleton h-7"></div></div></template>
          <template x-for="(it, i) in items" :key="it.id">
            <button type="button" class="menu-item" :class="hl === i && 'hl'" @mouseenter="hl = i" @click="pick(it)"><span class="min-w-0 flex-1"><span class="block truncate" x-text="it.title"></span><span class="block truncate text-xs text-muted" x-text="it.subtitle" x-show="it.subtitle"></span></span><span x-show="String(it.id) === value" class="text-primary-600"><?= icon('check', 'size-4') ?></span></button>
          </template>
          <template x-if="!loading && !items.length"><div class="px-3 py-2.5 text-xs text-muted">No matches</div></template>
        </div>
      </div>
    <?php break;
    case 'stage':
      $pipes = LookupService::pipelines(); ?>
      <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="select">
        <option value="">— First stage —</option>
        <?php foreach ($pipes as $p): ?><optgroup label="<?= e($p['name']) ?>"><?php foreach ($p['stages'] as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (string) $val === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= (int) $s['probability'] ?>%)</option><?php endforeach; ?></optgroup><?php endforeach; ?>
      </select>
    <?php break;
    case 'hidden': ?>
      <input type="hidden" name="<?= e($name) ?>" value="<?= e($val) ?>">
    <?php break;
    default: /* select, user, currency, tax, pipeline */
      $opts = LookupService::fieldOptions($f); ?>
      <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="select" <?= $req ?>>
        <option value=""><?= $f['type'] === 'user' ? 'Unassigned' : '—' ?></option>
        <?php foreach ($opts as $o): ?><option value="<?= e($o['value']) ?>" <?= (string) $val === (string) $o['value'] ? 'selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
<?php endswitch;
if (!empty($f['help'])): ?><p class="help"><?= e($f['help']) ?></p><?php endif; ?>
<p class="field-error" data-error-for="<?= e($name) ?>" hidden></p>

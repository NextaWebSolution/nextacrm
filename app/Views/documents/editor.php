<?php defined('NX_ROOT') || exit;
use App\Core\View;
$isQuote = $type === 'quote';
$entity = $isQuote ? 'quotes' : 'invoices';
View::share('breadcrumbs', [$isQuote ? 'Quotations' : 'Invoices' => url('/r/' . $entity)]);
$defaultTax = '';
foreach ($taxes as $t) { if ($t['is_default']) { $defaultTax = (string) $t['id']; } }
$cfg = [
    'items' => $items, 'products' => $products, 'taxes' => $taxes, 'defaultTax' => $defaultTax, 'currency' => $doc['currency'],
    'discountType' => $doc['discount_type'] ?? 'percent', 'discountValue' => (float) ($doc['discount_value'] ?? 0), 'adjustment' => (float) ($doc['adjustment'] ?? 0),
    'saveUrl' => $doc['id'] ? url('/documents/' . $type . '/' . $doc['id']) : url('/documents/' . $type),
];
$pick = fn (string $name, string $rel, string $label, array $where = []) => View::partial('partials/field_input', ['f' => ['name' => $name, 'type' => 'relation', 'relation' => $rel, 'label' => $label] + ($where ? ['depends' => $where[0]] : []), 'value' => $doc[$name] ?? '', 'display' => $doc['_labels'][$name] ?? null]);
?>
<div class="mx-auto max-w-6xl" x-data="docEditor(<?= ej($cfg) ?>)">
  <div class="mb-5 flex flex-wrap items-center gap-3">
    <div class="min-w-0 flex-1">
      <h1 class="text-xl font-semibold tracking-tight"><?= e($title) ?></h1>
      <p class="text-sm text-muted"><?= e($doc['number']) ?><?= $doc['id'] ? ' · ' . badge($doc['status']) : '' ?></p>
    </div>
    <a href="<?= e($doc['id'] ? url('/r/' . $entity . '/' . $doc['id']) : url('/r/' . $entity)) ?>" class="btn btn-secondary">Cancel</a>
    <?php if ($isQuote && (!$doc['id'] || $doc['status'] === 'Draft')): ?><button type="button" class="btn btn-secondary" @click="save('Sent')" :disabled="saving">Save &amp; mark sent</button><?php endif; ?>
    <button type="button" class="btn btn-primary" @click="save()" :disabled="saving"><span x-show="saving" x-cloak><?= icon('loader-circle', 'size-4 animate-spin') ?></span><?= $doc['id'] ? 'Save changes' : 'Create ' . ($isQuote ? 'quotation' : 'invoice') ?></button>
  </div>

  <form x-ref="form" @submit.prevent="save()" class="space-y-5" novalidate>
    <section class="card p-5">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div data-field class="sm:col-span-2"><label class="label">Customer <span class="text-red-500">*</span></label><?= $pick('customer_id', 'customers', 'Customer') ?></div>
        <div data-field><label class="label">Contact</label><?= $pick('contact_id', 'contacts', 'Contact', ['customer_id']) ?></div>
        <div data-field><label class="label">Project</label><?= $pick('project_id', 'projects', 'Project', ['customer_id']) ?></div>
        <?php if ($isQuote): ?><div data-field><label class="label">Deal</label><?= $pick('deal_id', 'deals', 'Deal', ['customer_id']) ?></div><?php endif; ?>
        <div data-field class="<?= $isQuote ? '' : 'sm:col-span-2' ?>"><label class="label" for="subject">Subject</label><input id="subject" name="subject" class="input" value="<?= e($doc['subject']) ?>" placeholder="e.g. Website redesign — phase 1"><p class="field-error" data-error-for="subject" hidden></p></div>
        <div data-field><label class="label" for="issue_date"><?= $isQuote ? 'Date' : 'Invoice date' ?></label><input id="issue_date" type="date" name="issue_date" class="input" value="<?= e($doc['issue_date']) ?>"></div>
        <?php if ($isQuote): ?>
          <div data-field><label class="label" for="valid_until">Valid until</label><input id="valid_until" type="date" name="valid_until" class="input" value="<?= e($doc['valid_until'] ?? '') ?>"></div>
        <?php else: ?>
          <div data-field><label class="label" for="due_date">Due date</label><input id="due_date" type="date" name="due_date" class="input" value="<?= e($doc['due_date'] ?? '') ?>"><p class="field-error" data-error-for="due_date" hidden></p></div>
        <?php endif; ?>
        <div data-field><label class="label" for="currency">Currency</label><select id="currency" name="currency" class="select" x-model="currency"><?php foreach ($currencies as $c): ?><option value="<?= e($c['code']) ?>" <?= $c['code'] === $doc['currency'] ? 'selected' : '' ?>><?= e($c['code']) ?> — <?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <?php if (!$isQuote): ?>
      <div class="mt-4 rounded-lg border border-theme p-3" x-data="{ rec: <?= !empty($doc['is_recurring']) ? 'true' : 'false' ?> }">
        <label class="flex items-center gap-2 text-sm font-medium"><input type="hidden" name="is_recurring" :value="rec ? 1 : 0"><input type="checkbox" class="checkbox" x-model="rec">Recurring invoice</label>
        <div x-show="rec" x-cloak class="mt-3 grid gap-3 sm:grid-cols-3">
          <div><label class="label">Repeat every</label><select name="recurring_interval" class="select"><?php foreach (['Weekly', 'Monthly', 'Quarterly', 'Half-yearly', 'Yearly'] as $iv): ?><option <?= ($doc['recurring_interval'] ?? 'Monthly') === $iv ? 'selected' : '' ?>><?= $iv ?></option><?php endforeach; ?></select></div>
          <div><label class="label">Next invoice on</label><input type="date" name="recurring_next_date" class="input" value="<?= e($doc['recurring_next_date'] ?? '') ?>"></div>
          <div><label class="label">Stop after</label><input type="date" name="recurring_end_date" class="input" value="<?= e($doc['recurring_end_date'] ?? '') ?>"></div>
          <p class="help sm:col-span-3">A new draft invoice is generated automatically on the scheduled date and the owner is notified.</p>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-header flex-wrap">
        <h2 class="card-title">Line items</h2>
        <div class="relative w-full sm:w-72" x-data="{ o: false }" @click.outside="o = false">
          <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint"><?= icon('package') ?></span>
          <input type="text" class="input input-sm pl-9" placeholder="Add product or service…" x-model="productQ" @focus="o = true" @input="o = true" @keydown.escape="o = false" aria-label="Add product">
          <div x-cloak x-show="o" class="menu left-0 right-0 mt-1 max-h-72 overflow-y-auto">
            <template x-for="p in productMatches" :key="p.id"><button type="button" class="menu-item" @click="add(p); o = false"><span class="min-w-0 flex-1 truncate" x-text="p.name"></span><span class="text-xs text-muted" x-text="fmt(p.price) + ' / ' + p.unit"></span></button></template>
            <template x-if="!productMatches.length"><div class="px-3 py-2 text-xs text-muted">No products found</div></template>
            <div class="menu-sep"></div><button type="button" class="menu-item" @click="add(); o = false"><?= icon('plus', 'size-4 text-faint') ?>Blank line</button>
          </div>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[46rem] text-[13px]">
          <thead><tr class="border-b border-theme text-left text-xs text-muted"><th class="w-8 px-3 py-2"></th><th class="px-2 py-2 font-medium">Description</th><th class="w-24 px-2 py-2 font-medium">Qty</th><th class="w-20 px-2 py-2 font-medium">Unit</th><th class="w-32 px-2 py-2 font-medium">Rate</th><th class="w-20 px-2 py-2 font-medium">Disc %</th><th class="w-32 px-2 py-2 font-medium">Tax</th><th class="w-32 px-3 py-2 text-right font-medium">Amount</th><th class="w-16"></th></tr></thead>
          <tbody>
            <template x-for="(it, i) in items" :key="i">
              <tr class="border-b border-theme align-top">
                <td class="px-3 py-2.5 text-xs text-faint" x-text="i + 1"></td>
                <td class="px-2 py-2"><textarea class="textarea min-h-[2.25rem] py-1.5 text-[13px]" rows="1" x-model="it.description" placeholder="Item description" @input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"></textarea></td>
                <td class="px-2 py-2"><input type="number" step="any" min="0" class="input input-sm text-right" x-model.number="it.quantity"></td>
                <td class="px-2 py-2"><input class="input input-sm" x-model="it.unit" placeholder="unit"></td>
                <td class="px-2 py-2"><input type="number" step="0.01" class="input input-sm text-right" x-model.number="it.unit_price"></td>
                <td class="px-2 py-2"><input type="number" step="0.01" min="0" max="100" class="input input-sm text-right" x-model.number="it.discount_percent"></td>
                <td class="px-2 py-2"><select class="select input-sm" x-model="it.tax_id"><option value="">No tax</option><?php foreach ($taxes as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></td>
                <td class="px-3 py-2.5 text-right font-medium tabular-nums" x-text="fmt(lineNet(it))"></td>
                <td class="py-2 pr-2"><div class="flex"><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="moveItem(i, -1)" title="Move up"><?= icon('arrow-up', 'size-3.5') ?></button><button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" @click="remove(i)" title="Remove line"><?= icon('trash', 'size-3.5') ?></button></div></td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <p class="field-error px-4" data-error-for="items" hidden></p>
      <div class="flex flex-col gap-6 p-4 lg:flex-row lg:justify-between">
        <button type="button" class="btn btn-ghost btn-sm self-start" @click="add()"><?= icon('plus') ?>Add line</button>
        <dl class="w-full max-w-sm space-y-2 text-[13px] lg:ml-auto">
          <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd class="tabular-nums" x-text="fmt(subtotal)"></dd></div>
          <div class="flex items-center justify-between gap-3"><dt class="flex items-center gap-2 text-muted">Discount
              <select class="select input-sm w-20" x-model="discountType"><option value="percent">%</option><option value="fixed">Fixed</option></select>
              <input type="number" step="0.01" min="0" class="input input-sm w-24 text-right" x-model.number="discountValue"></dt>
            <dd class="tabular-nums text-emerald-600" x-text="'−' + fmt(discount)"></dd></div>
          <div class="flex justify-between"><dt class="text-muted">Tax</dt><dd class="tabular-nums" x-text="fmt(taxTotal)"></dd></div>
          <div class="flex items-center justify-between gap-3"><dt class="text-muted">Adjustment / round-off</dt><dd><input type="number" step="0.01" class="input input-sm w-28 text-right" x-model.number="adjustment"></dd></div>
          <div class="flex justify-between border-t border-theme pt-2.5 text-base font-semibold"><dt>Total</dt><dd class="tabular-nums" x-text="fmt(total)"></dd></div>
        </dl>
      </div>
    </section>

    <section class="card grid gap-4 p-5 sm:grid-cols-2">
      <div><label class="label" for="notes">Notes for the customer</label><textarea id="notes" name="notes" rows="4" class="textarea" placeholder="Thank you for your business."><?= e($doc['notes']) ?></textarea></div>
      <div><label class="label" for="terms">Terms &amp; conditions</label><textarea id="terms" name="terms" rows="4" class="textarea"><?= e($doc['terms']) ?></textarea></div>
    </section>
  </form>
</div>

<?php defined('NX_ROOT') || exit; use App\Services\ApprovalService; ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="space-y-5" x-data="approvalFlows(<?= ej(['url' => url('/settings/approvals')]) ?>)">
  <div><h1 class="text-xl font-semibold tracking-tight">Approval chains</h1><p class="mt-1 text-sm text-muted">Require one or more people to approve large quotations or expenses. The chain with the highest threshold that applies is used.</p></div>
  <section class="card"><ul class="divide-theme">
    <?php foreach ($flows as $f): $steps = json_decode((string) $f['steps'], true) ?: []; ?>
      <li class="flex flex-wrap items-center gap-3 px-4 py-3">
        <div class="min-w-0 flex-1"><p class="font-medium"><?= e($f['name']) ?></p><p class="text-xs text-muted"><?= e(ApprovalService::ENTITIES[$f['entity']] ?? $f['entity']) ?> from <?= e(money((float) $f['min_amount'])) ?> · <?php foreach ($steps as $i => $s): ?><?= $i ? ' → ' : '' ?><?= e(ApprovalService::stepLabel($f, $i)) ?><?php endforeach; ?></p></div>
        <?= $f['is_active'] ? badge('Active', 'green') : badge('Off', 'slate') ?>
        <button type="button" class="btn btn-ghost btn-sm" @click="edit(<?= e(json_encode(['id' => (int) $f['id'], 'name' => $f['name'], 'entity' => $f['entity'], 'min_amount' => (float) $f['min_amount'], 'is_active' => (bool) $f['is_active'], 'steps' => $steps])) ?>)"><?= icon('pencil') ?></button>
        <button type="button" class="btn btn-ghost btn-sm text-faint hover:text-red-600" data-action="<?= e(url('/settings/approvals/' . $f['id'])) ?>" data-method="DELETE" data-confirm="Delete this approval chain?" data-danger><?= icon('trash') ?></button>
      </li>
    <?php endforeach; ?>
    <?php if (!$flows): ?><li class="px-4 py-8 text-center text-sm text-muted">No approval chains yet.</li><?php endif; ?>
  </ul></section>
  <section class="card">
    <div class="card-header"><h2 class="card-title" x-text="f.id ? 'Edit chain' : 'New chain'"></h2></div>
    <div class="grid gap-4 p-4 sm:grid-cols-2">
      <div><label class="label">Name</label><input class="input" x-model="f.name" placeholder="Large deals"></div>
      <div><label class="label">For</label><select class="select" x-model="f.entity"><?php foreach (ApprovalService::ENTITIES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div><label class="label">When the amount is at least</label><input type="number" min="0" step="any" class="input" x-model.number="f.min_amount"></div>
      <label class="mt-6 flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" x-model="f.is_active">Active</label>
      <div class="sm:col-span-2"><span class="label">Approval steps (in order)</span>
        <template x-for="(s, i) in f.steps" :key="i"><div class="mt-2 flex items-center gap-2"><span class="w-14 text-xs text-muted" x-text="'Step ' + (i + 1)"></span>
          <select class="select input-sm w-28" x-model="s.type"><option value="role">Role</option><option value="user">Person</option></select>
          <select class="select input-sm flex-1" x-model.number="s.id" x-show="s.type === 'role'"><option value="0">Choose a role…</option><?php foreach ($roles as $ro): ?><option value="<?= (int) $ro['id'] ?>"><?= e($ro['name']) ?></option><?php endforeach; ?></select>
          <select class="select input-sm flex-1" x-model.number="s.id" x-show="s.type === 'user'"><option value="0">Choose a person…</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select>
          <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="f.steps.splice(i, 1)" x-show="f.steps.length > 1" aria-label="Remove step"><?= icon('x', 'size-3.5') ?></button></div></template>
        <button type="button" class="btn btn-ghost btn-sm mt-2" @click="f.steps.push({ type: 'role', id: 0 })" x-show="f.steps.length < 5"><?= icon('plus') ?>Add step</button>
      </div>
    </div>
    <div class="flex justify-end gap-2 border-t border-theme p-4"><button type="button" class="btn btn-ghost" x-show="f.id" @click="reset()">Cancel</button><button type="button" class="btn btn-primary" @click="save()"><?= icon('save') ?>Save chain</button></div>
  </section>
</div>
</div>

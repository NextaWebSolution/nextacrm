<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<?php $cfg = ['wf' => $wf, 'entities' => $entities, 'users' => array_map(fn ($u) => ['id' => $u['id'], 'name' => $u['name']], $users)]; ?>
<div x-data="workflowBuilder(<?= ej($cfg) ?>)" class="space-y-5">
  <section class="card grid gap-4 p-5 sm:grid-cols-[1fr_auto]">
    <div><label class="label">Automation name</label><input class="input" x-model="name" placeholder="e.g. Assign website leads round-robin"></div>
    <label class="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" class="checkbox" x-model="active">Active</label>
  </section>

  <section class="card p-5">
    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold"><span class="grid size-6 place-items-center rounded-full bg-primary-600 text-xs text-white">1</span>When</h2>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label class="label">Module</label><select class="select" x-model="entity" @change="conditions = []; tcfg = {}"><template x-for="(e, k) in entities" :key="k"><option :value="k" x-text="e.label" :selected="k === entity"></option></template></select></div>
      <div><label class="label">Trigger</label><select class="select" x-model="trigger"><?php foreach (App\Services\WorkflowService::TRIGGERS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <template x-if="trigger === 'field_changed'"><div class="contents"><div><label class="label">Field</label><select class="select" x-model="tcfg.field"><option value="">Choose…</option><template x-for="f in fields" :key="f.name"><option :value="f.name" x-text="f.label" :selected="f.name === tcfg.field"></option></template></select></div>
        <div><label class="label">Changes to (optional)</label><template x-if="field(tcfg.field).options.length"><select class="select" x-model="tcfg.to"><option value="">Any value</option><template x-for="o in field(tcfg.field).options" :key="o.value"><option :value="o.value" x-text="o.label" :selected="o.value === tcfg.to"></option></template></select></template><template x-if="!field(tcfg.field).options.length"><input class="input" x-model="tcfg.to" placeholder="Any value"></template></div></div></template>
      <template x-if="trigger === 'scheduled'"><div class="contents"><div><label class="label">Date field</label><select class="select" x-model="tcfg.date_field"><option value="">Choose…</option><template x-for="f in dateFields" :key="f.name"><option :value="f.name" x-text="f.label" :selected="f.name === tcfg.date_field"></option></template></select></div>
        <div><label class="label">Offset in days</label><input type="number" class="input" x-model.number="tcfg.offset_days" placeholder="-3 = three days before, 0 = on the day, 2 = two days after"><p class="help">Checked once a day by the scheduler.</p></div></div></template>
      <template x-if="trigger === 'webhook'"><p class="text-sm text-muted sm:col-span-2"><?= $wf && $wf['webhook_token'] ? 'POST JSON to <code class="rounded bg-surface-2 px-1">' . e(full_url('/hooks/workflow/' . $wf['webhook_token'])) . '</code>. Include <code>"id"</code> to act on an existing record, otherwise a new record is created from the JSON fields.' : 'Save to generate the webhook URL.' ?></p></template>
      <template x-if="trigger === 'manual'"><p class="text-sm text-muted sm:col-span-2">Appears in the record's “…” menu so users can run it on demand.</p></template>
    </div>
  </section>

  <section class="card p-5">
    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold"><span class="grid size-6 place-items-center rounded-full bg-primary-600 text-xs text-white">2</span>Only if <span class="font-normal text-muted">(all conditions must match)</span></h2>
    <div class="space-y-2">
      <template x-for="(c, i) in conditions" :key="i">
        <div class="flex flex-wrap items-center gap-2">
          <select class="select input-sm w-44" x-model="c.field"><template x-for="f in fields" :key="f.name"><option :value="f.name" x-text="f.label" :selected="f.name === c.field"></option></template></select>
          <select class="select input-sm w-36" x-model="c.op"><?php foreach (App\Services\WorkflowService::CONDITION_OPS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
          <template x-if="!['empty','not_empty','changed'].includes(c.op) && field(c.field).options.length"><select class="select input-sm w-44" x-model="c.value"><template x-for="o in field(c.field).options" :key="o.value"><option :value="o.value" x-text="o.label" :selected="o.value === c.value"></option></template></select></template>
          <template x-if="!['empty','not_empty','changed'].includes(c.op) && !field(c.field).options.length"><input class="input input-sm w-44" x-model="c.value"></template>
          <button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" @click="conditions.splice(i, 1)"><?= icon('x', 'size-3.5') ?></button>
        </div>
      </template>
      <button type="button" class="btn btn-ghost btn-sm" @click="addCondition()"><?= icon('plus') ?>Add condition</button>
    </div>
  </section>

  <section class="card p-5">
    <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold"><span class="grid size-6 place-items-center rounded-full bg-primary-600 text-xs text-white">3</span>Then</h2>
    <div class="space-y-3">
      <template x-for="(a, i) in actions" :key="i">
        <div class="rounded-xl border border-theme p-3">
          <div class="flex items-center gap-2"><select class="select input-sm w-56" x-model="a.type"><?php foreach (App\Services\WorkflowService::ACTIONS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select><button type="button" class="btn btn-ghost btn-icon btn-sm ml-auto text-faint hover:text-red-600" @click="actions.splice(i, 1)"><?= icon('trash', 'size-3.5') ?></button></div>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <template x-if="a.type === 'update_field'"><div class="contents"><div><label class="label">Field</label><select class="select input-sm" x-model="a.field"><option value="">Choose…</option><template x-for="f in writable" :key="f.name"><option :value="f.name" x-text="f.label" :selected="f.name === a.field"></option></template></select></div><div><label class="label">New value</label><template x-if="field(a.field).options.length"><select class="select input-sm" x-model="a.value"><template x-for="o in field(a.field).options" :key="o.value"><option :value="o.value" x-text="o.label" :selected="o.value === a.value"></option></template></select></template><template x-if="!field(a.field).options.length"><input class="input input-sm" x-model="a.value" placeholder="Text, number, or {{today+7}}"></template></div></div></template>
            <template x-if="a.type === 'assign_user'"><div><label class="label">User</label><select class="select input-sm" x-model="a.user_id"><template x-for="u in users" :key="u.id"><option :value="u.id" x-text="u.name" :selected="String(u.id) === String(a.user_id)"></option></template></select></div></template>
            <template x-if="a.type === 'round_robin'"><div class="sm:col-span-2"><label class="label">Rotate between</label><div class="flex flex-wrap gap-3"><template x-for="u in users" :key="u.id"><label class="flex items-center gap-1.5 text-sm"><input type="checkbox" class="checkbox" :checked="(a.user_ids || []).includes(u.id)" @change="toggleRR(a, u.id)"><span x-text="u.name"></span></label></template></div></div></template>
            <template x-if="a.type === 'create_task'"><div class="contents"><div><label class="label">Task title</label><input class="input input-sm" x-model="a.title" :placeholder="'Follow up with ' + placeholder()"></div><div class="grid grid-cols-2 gap-2"><div><label class="label">Due in (days)</label><input type="number" class="input input-sm" x-model.number="a.due_in_days"></div><div><label class="label">Priority</label><select class="select input-sm" x-model="a.priority"><option>Low</option><option selected>Medium</option><option>High</option><option>Urgent</option></select></div></div><div><label class="label">Assign to</label><select class="select input-sm" x-model="a.assign_to"><option value="owner">Record owner</option><option value="creator">Record creator</option><template x-for="u in users" :key="u.id"><option :value="'user:' + u.id" x-text="u.name"></option></template></select></div></div></template>
            <template x-if="a.type === 'send_email'"><div class="contents"><div><label class="label">To</label><select class="select input-sm" x-model="a.to"><option value="owner">Record owner</option><option value="creator">Record creator</option><option value="record">The record's e-mail (customer)</option><option value="custom">Specific address</option></select></div><div x-show="a.to === 'custom'"><label class="label">E-mail</label><input class="input input-sm" x-model="a.email"></div><div class="sm:col-span-2"><label class="label">Subject</label><input class="input input-sm" x-model="a.subject"></div><div class="sm:col-span-2"><label class="label">Body</label><textarea class="textarea text-[13px]" rows="4" x-model="a.body"></textarea></div></div></template>
            <template x-if="a.type === 'notify'"><div class="contents"><div><label class="label">Notify</label><select class="select input-sm" x-model="a.to"><option value="owner">Record owner</option><option value="creator">Record creator</option><template x-for="u in users" :key="u.id"><option :value="'user:' + u.id" x-text="u.name"></option></template></select></div><div><label class="label">Title</label><input class="input input-sm" x-model="a.title"></div><div class="sm:col-span-2"><label class="label">Message</label><textarea class="textarea text-[13px]" rows="2" x-model="a.body"></textarea></div></div></template>
            <template x-if="a.type === 'add_note'"><div class="sm:col-span-2"><label class="label">Note</label><textarea class="textarea text-[13px]" rows="2" x-model="a.body"></textarea></div></template>
            <template x-if="a.type === 'webhook'"><div class="sm:col-span-2"><label class="label">URL (receives the record as JSON via POST)</label><input class="input input-sm" x-model="a.url" placeholder="https://"></div></template>
          </div>
        </div>
      </template>
      <button type="button" class="btn btn-ghost btn-sm" @click="addAction()"><?= icon('plus') ?>Add action</button>
      <p class="text-xs text-faint">Use placeholders like <code x-text="placeholder()"></code>, <code>{{record_url}}</code>, <code>{{today}}</code>, <code>{{today+3}}</code> in text.</p>
    </div>
  </section>

  <div class="flex justify-between gap-2">
    <?php if ($wf): ?><button type="button" class="btn btn-danger-ghost" data-action="<?= e(url('/settings/workflows/' . $wf['id'])) ?>" data-method="DELETE" data-confirm="Delete this automation?" data-danger>Delete</button><?php else: ?><span></span><?php endif; ?>
    <div class="flex gap-2"><a href="<?= e(url('/settings/workflows')) ?>" class="btn btn-secondary">Cancel</a><button type="button" class="btn btn-primary" @click="save()" :disabled="saving">Save automation</button></div>
  </div>
</div>
</div></div>

<?php defined('NX_ROOT') || exit;
use App\Core\View;
use App\Services\EntityRegistry;
use App\Services\FieldRenderer;
use App\Services\LookupService;

View::share('breadcrumbs', [$def['label'] => url('/r/' . $entity)]);
$fields = EntityRegistry::visibleFields($def);
$id = (int) $record['id'];
$statusField = isset($fields['status']) ? 'status' : null;
$isDoc = in_array($entity, ['quotes', 'invoices'], true);
$docType = $entity === 'quotes' ? 'quote' : 'invoice';
$sub = $def['subtitle'] ?? null;
$inlineOk = ['text', 'email', 'phone', 'url', 'number', 'decimal', 'money', 'percent', 'date', 'datetime', 'select', 'user', 'bool', 'textarea', 'tags', 'currency', 'tax', 'stage', 'duration'];
$groups = [];
foreach ($fields as $n => $f) {
    if ($f['type'] === 'hidden' || ($f['form'] ?? true) === false && $n !== $def['title']) continue;
    if (in_array($n, ['created_at', 'updated_at', 'created_by'], true)) continue;
    $groups[$f['group'] ?? 'Details'][$n] = $f;
}
$tabs = ['activity' => 'Activity'];
foreach ($related as $i => $rel) { $tabs['rel' . $i] = $rel['def']['label'] . ' (' . $rel['result']['total'] . ')'; }
$tabs['files'] = 'Files (' . count($files) . ')';
if ($history) { $tabs['history'] = 'History'; }
$stage = $record['_stage'] ?? null;
?>
<div class="mx-auto max-w-[1400px]" data-page-entity="<?= e($entity) ?>" data-can-create="0">
  <!-- Header -->
  <div class="mb-5 flex flex-wrap items-start gap-3">
    <a href="<?= e(url('/r/' . $entity)) ?>" class="btn btn-ghost btn-icon btn-sm mt-1 md:hidden" aria-label="Back"><?= icon('arrow-left') ?></a>
    <span class="hidden size-11 shrink-0 place-items-center rounded-xl border border-theme surface text-muted sm:grid"><?= icon($def['icon'], 'size-5') ?></span>
    <div class="min-w-0 flex-1">
      <div class="flex flex-wrap items-center gap-2">
        <h1 class="truncate text-xl font-semibold tracking-tight sm:text-2xl"><?= e($record['_title']) ?></h1>
        <?php if ($statusField && !empty($record['status'])): ?><?= FieldRenderer::html($fields['status'], $record, $svc) ?><?php endif; ?>
        <?php if ($stage): ?><?= badge($stage['name'], $stage['color']) ?><?php endif; ?>
        <?php if ($entity === 'tickets' && !empty($record['sla_status'])): ?><?= badge('SLA ' . $record['sla_status'], $record['sla_status'] === 'Breached' ? 'red' : ($record['sla_status'] === 'At Risk' ? 'amber' : 'green')) ?><?php endif; ?>
        <?php if ($entity === 'leads' && $record['score']): ?><span class="badge badge-violet before:hidden" title="Lead score"><?= icon('gauge', 'size-3') ?><?= (int) $record['score'] ?></span><?php endif; ?>
      </div>
      <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
        <span><?= e($def['singular']) ?> #<?= $id ?></span>
        <?php if ($sub && !empty($record[$sub]) && $sub !== $def['title']): ?><span>·</span><span><?= FieldRenderer::html($fields[$sub], $record, $svc) ?></span><?php endif; ?>
        <?php if (!empty($record['assigned_to'])): ?><span>·</span><span class="inline-flex items-center gap-1.5"><?= FieldRenderer::html($fields['assigned_to'], $record, $svc) ?></span><?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <?php if ($entity === 'leads' && empty($record['converted_at']) && $canEdit): ?>
        <button type="button" class="btn btn-primary" x-data @click="$dispatch('open-convert')"><?= icon('repeat') ?>Convert</button>
      <?php endif; ?>
      <?php if (in_array($entity, ['tasks'], true) && can('time_entries', 'create')): $running = !empty($timer) && (int) $timer['task_id'] === $id; ?>
        <button type="button" class="btn btn-secondary" x-data="timerButton(<?= ej(['taskId' => $id, 'running' => $running]) ?>)" @click="toggle()"><span x-show="!running"><?= icon('play', 'size-4') ?></span><span x-show="running" x-cloak class="text-red-600"><?= icon('square', 'size-4') ?></span><span x-text="running ? 'Stop timer' : 'Start timer'"></span></button>
      <?php endif; ?>
      <?php if ($isDoc): ?>
        <a href="<?= e(url('/documents/' . $docType . '/' . $id . '/pdf')) ?>" class="btn btn-secondary"><?= icon('download') ?>PDF</a>
        <?php if ($canEdit): ?>
          <button type="button" class="btn btn-secondary" x-data @click="$dispatch('open-email')"><?= icon('send') ?>Send</button>
          <a href="<?= e(url('/documents/' . $docType . '/' . $id . '/edit')) ?>" class="btn btn-secondary"><?= icon('pencil') ?>Edit</a>
        <?php endif; ?>
        <?php if ($entity === 'invoices' && can('payments', 'create') && !in_array($record['status'], ['Paid', 'Cancelled', 'Draft'], true)): ?>
          <button type="button" class="btn btn-primary" x-data @click="$dispatch('open-form', { entity: 'payments', prefill: { invoice_id: '<?= $id ?>', amount: '<?= e((string) $record['balance']) ?>', paid_on: '<?= date('Y-m-d') ?>' }, onSaved: 'reload' })"><?= icon('credit-card') ?>Record payment</button>
        <?php endif; ?>
        <?php if ($entity === 'quotes' && can('invoices', 'create') && in_array($record['status'], ['Accepted', 'Sent'], true)): ?>
          <button type="button" class="btn btn-primary" data-action="<?= e(url('/documents/quote/' . $id . '/convert')) ?>" data-confirm="Create an invoice from this quotation?"><?= icon('receipt') ?>Convert to invoice</button>
        <?php endif; ?>
      <?php elseif ($canEdit): ?>
        <button type="button" class="btn btn-secondary" x-data @click="$dispatch('open-form', { entity: '<?= e($entity) ?>', id: <?= $id ?> })"><?= icon('pencil') ?>Edit</button>
      <?php endif; ?>
      <div class="relative" x-data="{ o: false }">
        <button type="button" class="btn btn-secondary btn-icon" @click="o = !o" aria-label="More actions"><?= icon('ellipsis') ?></button>
        <div x-cloak x-show="o" @click.outside="o = false" class="menu right-0 mt-2 w-60">
          <?php if ($isDoc && $canEdit): ?>
            <?php foreach ($entity === 'quotes' ? ['Sent' => 'Mark as sent', 'Accepted' => 'Mark as accepted', 'Declined' => 'Mark as declined', 'Draft' => 'Back to draft'] : ['Sent' => 'Mark as sent', 'Draft' => 'Back to draft', 'Cancelled' => 'Cancel invoice'] as $st => $lbl): if ($st === $record['status']) continue; ?>
              <button type="button" class="menu-item" data-action="<?= e(url('/documents/' . $docType . '/' . $id . '/status')) ?>" data-body="<?= ej(['status' => $st]) ?>" <?= $st === 'Cancelled' ? 'data-confirm="Cancel this invoice? It will no longer count as outstanding." data-danger' : '' ?>><?= icon('circle-dot', 'size-4 text-faint') ?><?= e($lbl) ?></button>
            <?php endforeach; ?>
            <?php if (!empty($record['public_token']) && $record['status'] !== 'Draft'): ?><button type="button" class="menu-item" data-copy="<?= e(App\Services\SalesService::publicUrl($docType, App\Core\App::db()->find($svc->table, $id))) ?>"><?= icon('link', 'size-4 text-faint') ?>Copy customer link</button><?php endif; ?>
            <div class="menu-sep"></div>
          <?php endif; ?>
          <?php if ($entity === 'projects' && can('invoices', 'create')): ?>
            <button type="button" class="menu-item" data-action="<?= e(url('/documents/invoice/from-time/' . $id)) ?>" data-confirm="Create a draft invoice from all unbilled billable time on this project?"><?= icon('receipt', 'size-4 text-faint') ?>Invoice unbilled time</button>
            <a class="menu-item" href="<?= e(url('/r/tasks', ['view' => 'board', 'f' => ['project_id:eq:' . $id]])) ?>"><?= icon('kanban', 'size-4 text-faint') ?>Task board</a>
            <div class="menu-sep"></div>
          <?php endif; ?>
          <?php if (in_array($entity, ['customers', 'deals', 'projects'], true)): ?>
            <?php if (can('quotes', 'create')): ?><a class="menu-item" href="<?= e(url('/documents/quote/new', array_filter(['customer_id' => $entity === 'customers' ? $id : ($record['customer_id'] ?? null), 'deal_id' => $entity === 'deals' ? $id : null, 'project_id' => $entity === 'projects' ? $id : null]))) ?>"><?= icon('file-text', 'size-4 text-faint') ?>New quotation</a><?php endif; ?>
            <?php if (can('invoices', 'create')): ?><a class="menu-item" href="<?= e(url('/documents/invoice/new', array_filter(['customer_id' => $entity === 'customers' ? $id : ($record['customer_id'] ?? null), 'project_id' => $entity === 'projects' ? $id : null]))) ?>"><?= icon('receipt', 'size-4 text-faint') ?>New invoice</a><?php endif; ?>
            <div class="menu-sep"></div>
          <?php endif; ?>
          <?php if (can('events', 'create')): ?><button type="button" class="menu-item" @click="o = false; $dispatch('open-form', { entity: 'events', prefill: { related_entity: '<?= e($entity) ?>', related_id: '<?= $id ?>' }, stay: true, onSaved: 'reload' })"><?= icon('calendar-days', 'size-4 text-faint') ?>Schedule meeting</button><?php endif; ?>
          <?php if (can('tasks', 'create') && $entity !== 'tasks'): ?><button type="button" class="menu-item" @click="o = false; $dispatch('open-form', { entity: 'tasks', prefill: <?= ej(array_filter(['related_entity' => $entity, 'related_id' => (string) $id, 'project_id' => $entity === 'projects' ? (string) $id : null])) ?>, stay: true, onSaved: 'reload' })"><?= icon('square-check-big', 'size-4 text-faint') ?>Add task</button><?php endif; ?>
          <?php foreach ($manualWorkflows as $wf): ?><button type="button" class="menu-item" data-action="<?= e(url('/r/' . $entity . '/' . $id . '/workflow/' . $wf['id'])) ?>"><?= icon('zap', 'size-4 text-faint') ?><?= e($wf['name']) ?></button><?php endforeach; ?>
          <?php if ($canDelete): ?><div class="menu-sep"></div><button type="button" class="menu-item text-red-600 dark:text-red-400" data-action="<?= e(url('/r/' . $entity . '/' . $id)) ?>" data-method="DELETE" data-confirm="Delete this <?= e(strtolower($def['singular'])) ?>? This cannot be undone from the app." data-danger data-ok="Delete"><?= icon('trash', 'size-4') ?>Delete</button><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($entity === 'deals' && !empty($record['pipeline_id'])): $pl = array_values(array_filter(LookupService::pipelines(), fn ($p) => (int) $p['id'] === (int) $record['pipeline_id']))[0] ?? null; ?>
    <?php if ($pl): ?>
    <div class="mb-5 flex overflow-x-auto rounded-xl border border-theme surface p-1 text-xs font-medium">
      <?php $reached = true; foreach ($pl['stages'] as $s): $cur = (int) $s['id'] === (int) $record['stage_id']; ?>
        <button type="button" <?= $canEdit ? '' : 'disabled' ?> class="flex min-w-28 flex-1 items-center justify-center gap-1.5 rounded-lg px-3 py-2 transition-colors <?= $cur ? ($s['is_lost'] ? 'bg-red-600 text-white' : ($s['is_won'] ? 'bg-emerald-600 text-white' : 'bg-primary-600 text-white')) : ($reached && !$s['is_lost'] ? 'bg-primary-50 text-primary-800 dark:bg-primary-900/30 dark:text-primary-300' : 'text-muted hover:bg-[color-mix(in_oklab,var(--text)_5%,transparent)]') ?>"
          x-data @click="NX.api('<?= e(url('/x/r/deals/' . $id)) ?>', { method: 'PATCH', body: { field: 'stage_id', value: '<?= (int) $s['id'] ?>' } }).then(() => { NX.toast('Stage updated'); setTimeout(() => location.reload(), 300); }).catch(NX.fail)">
          <?= $cur ? icon('check', 'size-3.5') : '' ?><?= e($s['name']) ?></button>
      <?php if ($cur) $reached = false; endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="min-w-0 space-y-5">
      <?php if ($isDoc): ?>
        <?= View::partial('documents/_preview', $doc + ['compact' => false]) ?>
      <?php endif; ?>

      <?php if ($entity === 'projects'): $s = $projectStats; $budgetUsed = $record['budget'] > 0 ? min(999, (int) round(($s['time_value'] + $s['expenses']) / $record['budget'] * 100)) : null; ?>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="card p-4"><p class="text-xs text-muted">Progress</p><p class="mt-1 text-xl font-semibold"><?= (int) $record['progress'] ?>%</p><span class="progress mt-2 block w-full"><span style="width:<?= (int) $record['progress'] ?>%"></span></span></div>
          <div class="card p-4"><p class="text-xs text-muted">Tasks</p><p class="mt-1 text-xl font-semibold"><?= $s['tasks_done'] ?><span class="text-sm font-normal text-faint"> / <?= $s['tasks_total'] ?></span></p><p class="mt-1 text-xs <?= $s['tasks_overdue'] ? 'text-red-600' : 'text-faint' ?>"><?= $s['tasks_overdue'] ?> overdue</p></div>
          <div class="card p-4"><p class="text-xs text-muted">Hours logged</p><p class="mt-1 text-xl font-semibold"><?= e((string) $s['hours']) ?>h</p><p class="mt-1 text-xs text-faint"><?= e((string) $s['unbilled_hours']) ?>h unbilled · est. <?= e((string) $s['estimate_hours']) ?>h</p></div>
          <div class="card p-4"><p class="text-xs text-muted">Invoiced / paid</p><p class="mt-1 truncate text-xl font-semibold"><?= e(money($s['invoiced'], $record['currency'])) ?></p><p class="mt-1 truncate text-xs text-faint"><?= e(money($s['paid'], $record['currency'])) ?> paid<?= $budgetUsed !== null ? ' · ' . $budgetUsed . '% of budget used' : '' ?></p></div>
        </div>
        <section class="card">
          <div class="card-header"><h2 class="card-title">Team</h2>
            <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('/r/projects/' . $id . '/members')) ?>" data-ajax class="flex items-center gap-2">
              <?= csrf_field() ?>
              <select name="user_id" class="select input-sm w-44" required aria-label="Add member"><option value="">Add member…</option><?php foreach (LookupService::companyUsers() as $cu): ?><option value="<?= (int) $cu['id'] ?>"><?= e($cu['name']) ?></option><?php endforeach; ?></select>
              <button class="btn btn-secondary btn-sm" type="submit"><?= icon('user-plus') ?></button>
            </form>
            <?php endif; ?>
          </div>
          <ul class="flex flex-wrap gap-2 p-4">
            <?php foreach ($members as $m): ?>
              <li class="flex items-center gap-2 rounded-full border border-theme py-1 pl-1 pr-3 text-[13px]"><?= FieldRenderer::avatar($m['name'], $m['hue'], 'size-6 text-[9px]') ?><span><?= e($m['name']) ?></span><?php if ($m['role']): ?><span class="text-xs text-faint"><?= e($m['role']) ?></span><?php endif; ?>
                <?php if ($canEdit && (int) $m['user_id'] !== (int) $record['assigned_to']): ?><button type="button" class="text-faint hover:text-red-600" data-action="<?= e(url('/r/projects/' . $id . '/members/' . $m['user_id'])) ?>" data-method="DELETE" data-confirm="Remove <?= e($m['name']) ?> from this project?" aria-label="Remove"><?= icon('x', 'size-3.5') ?></button><?php endif; ?></li>
            <?php endforeach; ?>
            <?php if (!$members): ?><li class="text-sm text-muted">No members yet.</li><?php endif; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($entity === 'tickets'): ?>
        <section class="card">
          <div class="card-header"><h2 class="card-title">Conversation</h2><span class="text-xs text-faint"><?= count($replies) ?> replies</span></div>
          <div class="space-y-4 p-4">
            <div class="flex gap-3">
              <span class="avatar size-8 text-[11px]" style="--h:<?= hue_for((string) ($record['requester_email'] ?? 'customer')) ?>"><?= e(initials($record['requester_name'] ?: ($record['_labels']['contact_id'] ?? 'Customer'))) ?></span>
              <div class="min-w-0 flex-1 rounded-lg border border-theme bg-surface-2 px-3.5 py-2.5"><p class="text-xs"><span class="font-medium"><?= e($record['requester_name'] ?: ($record['_labels']['contact_id'] ?? 'Customer')) ?></span> <span class="text-faint">opened · <?= e(fmt_datetime($record['created_at'])) ?></span></p><div class="mt-1 text-[13px]"><?= nl2br_safe($record['description'] ?: '(no description)') ?></div></div>
            </div>
            <?php foreach ($replies as $rp): ?>
              <div class="flex gap-3">
                <span class="avatar size-8 text-[11px]" style="--h:<?= hue_for((string) $rp['author_email']) ?>"><?= e(initials($rp['author_name'])) ?></span>
                <div class="min-w-0 flex-1 rounded-lg border px-3.5 py-2.5 <?= $rp['is_internal'] ? 'border-amber-200 bg-amber-50 dark:border-amber-900/50 dark:bg-amber-950/30' : 'border-theme surface' ?>">
                  <p class="text-xs"><span class="font-medium"><?= e($rp['author_name']) ?></span> <span class="text-faint"><?= $rp['is_internal'] ? 'internal note' : 'replied' ?> · <?= e(fmt_datetime($rp['created_at'])) ?></span></p>
                  <div class="mt-1 text-[13px]"><?= nl2br_safe($rp['body']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($canEdit): ?>
          <form method="post" action="<?= e(url('/r/tickets/' . $id . '/reply')) ?>" data-ajax data-reset class="border-t border-theme p-4" x-data="{ internal: false }">
            <?= csrf_field() ?>
            <div class="seg mb-2"><button type="button" :class="!internal && 'on'" @click="internal = false">Reply to customer</button><button type="button" :class="internal && 'on'" @click="internal = true">Internal note</button></div>
            <input type="hidden" name="internal" :value="internal ? 1 : 0">
            <textarea name="body" rows="4" class="textarea" :class="internal && 'bg-amber-50/60 dark:bg-amber-950/20'" :placeholder="internal ? 'Visible to your team only…' : 'Write your reply…'" required></textarea>
            <div class="mt-2 flex flex-wrap items-center gap-3">
              <label class="flex items-center gap-2 text-xs text-muted" x-show="!internal"><input type="checkbox" class="checkbox" name="notify" value="1" checked>E-mail the customer</label>
              <select name="status" class="select input-sm ml-auto w-auto" aria-label="Set status"><option value="">Keep status (<?= e($record['status']) ?>)</option><?php foreach (App\Services\EntityRegistry::options($fields['status']) as $k => $l): ?><option value="<?= e($k) ?>">Set to <?= e($l) ?></option><?php endforeach; ?></select>
              <button type="submit" class="btn btn-primary btn-sm"><?= icon('send') ?><span x-text="internal ? 'Add note' : 'Send reply'"></span></button>
            </div>
          </form>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <!-- Tabs -->
      <section class="card" x-data="{ tab: 'activity' }">
        <div class="tabs px-3">
          <?php foreach ($tabs as $k => $label): ?><button type="button" class="tab" :class="tab === '<?= $k ?>' && 'active'" @click="tab = '<?= $k ?>'"><?= e($label) ?></button><?php endforeach; ?>
        </div>

        <div x-show="tab === 'activity'" class="p-4" x-data="timeline(<?= ej(['url' => url('/r/' . $entity . '/' . $id . '/activities')]) ?>)">
          <div class="mb-5 rounded-xl border border-theme p-3">
            <div class="mb-2 flex flex-wrap gap-1">
              <?php foreach (['note' => ['message-square', 'Note'], 'call' => ['phone', 'Call'], 'meeting' => ['users', 'Meeting'], 'email' => ['mail', 'Email'], 'whatsapp' => ['smartphone', 'WhatsApp']] as $tk => [$ic, $tl]): ?>
                <button type="button" class="btn btn-xs" :class="type === '<?= $tk ?>' ? 'btn-secondary' : 'btn-ghost'" @click="type = '<?= $tk ?>'"><?= icon($ic, 'size-3.5') ?><?= $tl ?></button>
              <?php endforeach; ?>
            </div>
            <input x-show="type !== 'note'" x-cloak type="text" class="input input-sm mb-2" x-model="subject" placeholder="Subject / outcome (optional)">
            <textarea class="textarea min-h-16 border-0 px-1 shadow-none focus:shadow-none" rows="2" x-model="body" placeholder="Add a note, log a call or meeting…" @keydown.ctrl.enter="submit()" @keydown.meta.enter="submit()"></textarea>
            <div class="mt-2 flex items-center gap-2">
              <input x-show="type !== 'note'" x-cloak type="datetime-local" class="input input-sm w-auto" x-model="when" aria-label="When">
              <span class="hidden text-xs text-faint sm:inline">Ctrl + Enter to save</span>
              <button type="button" class="btn btn-primary btn-sm ml-auto" @click="submit()" :disabled="saving"><?= icon('save') ?>Save</button>
            </div>
          </div>
          <ul x-ref="list">
            <?php foreach ($activities as $a): ?><?= View::partial('records/_activity_item', ['a' => $a]) ?><?php endforeach; ?>
            <?php if (!$activities): ?><li data-empty class="py-6 text-center text-sm text-muted">No activity yet.</li><?php endif; ?>
          </ul>
        </div>

        <?php foreach ($related as $i => $rel): $rsvc = $rel['svc']; $rdef = $rsvc->def; $rf = EntityRegistry::visibleFields($rdef);
          $rcols = array_slice(array_keys(array_filter($rf, fn ($f) => !empty($f['list']))), 0, 5);
          $prefill = !empty($rel['def']['morph']) ? ['related_entity' => $entity, 'related_id' => (string) $id] : [$rel['def']['fk'] => (string) $id]; ?>
          <div x-show="tab === 'rel<?= $i ?>'" x-cloak>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-xs text-muted"><?= (int) $rel['result']['total'] ?> <?= e(strtolower($rdef['label'])) ?></span>
              <?php if (can($rdef['module'], 'create')): ?>
                <?php if (!empty($rdef['document'])): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('/documents/' . $rdef['document'] . '/new', array_filter(['customer_id' => $entity === 'customers' ? $id : null, 'project_id' => $entity === 'projects' ? $id : null, 'deal_id' => $entity === 'deals' ? $id : null]))) ?>"><?= icon('plus') ?>New</a>
                <?php else: ?><button type="button" class="btn btn-secondary btn-sm" @click="$dispatch('open-form', { entity: '<?= e($rel['def']['entity']) ?>', prefill: <?= ej($prefill) ?>, stay: true, onSaved: 'reload' })"><?= icon('plus') ?>New <?= e(strtolower($rdef['singular'])) ?></button><?php endif; ?>
              <?php endif; ?>
            </div>
            <?php if (!$rel['result']['rows']): ?>
              <p class="px-4 pb-8 pt-4 text-center text-sm text-muted">Nothing here yet.</p>
            <?php else: ?>
            <div class="overflow-x-auto border-t border-theme">
              <table class="data-table">
                <thead><tr><?php foreach ($rcols as $c): ?><th><?= e($rf[$c]['label']) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                  <?php foreach ($rel['result']['rows'] as $row): ?>
                    <tr class="cursor-pointer" data-href="<?= e(url('/r/' . $rel['def']['entity'] . '/' . $row['id'])) ?>">
                      <?php foreach ($rcols as $j => $c): ?><td class="max-w-[16rem] truncate"><?= $j === 0 ? '<span class="font-medium">' . e($row['_title']) . '</span>' : FieldRenderer::html($rf[$c], $row, $rsvc) ?></td><?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div x-show="tab === 'files'" x-cloak class="p-4" x-data="uploader(<?= ej(['url' => url('/r/' . $entity . '/' . $id . '/files')]) ?>)">
          <div class="flex cursor-pointer flex-col items-center rounded-xl border-2 border-dashed px-4 py-7 text-center transition-colors" :class="over ? 'border-primary-400 bg-primary-50/50 dark:bg-primary-900/10' : 'border-theme'"
               @click="pick()" @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="drop($event)">
            <span class="text-faint"><?= icon('upload', 'size-6') ?></span>
            <p class="mt-2 text-sm"><span class="link font-medium">Choose files</span> or drag and drop</p>
            <p class="mt-0.5 text-xs text-faint">Up to <?= (int) setting('security.max_upload_mb', 25) ?> MB each</p>
            <input type="file" x-ref="input" class="hidden" multiple @change="changed($event)">
          </div>
          <template x-for="u in uploads" :key="u.name"><div class="mt-2 flex items-center gap-3 text-xs"><span class="truncate" x-text="u.name"></span><span class="progress w-28"><span :style="'width:' + u.pct + '%'"></span></span><span class="text-red-600" x-text="u.error"></span></div></template>
          <ul x-ref="list" class="mt-3 divide-theme">
            <?php foreach ($files as $f): ?><?= View::partial('records/_file_item', ['f' => $f, 'canDelete' => $canEdit || (int) $f['uploaded_by'] === (int) user()['id']]) ?><?php endforeach; ?>
            <?php if (!$files): ?><li data-empty class="py-4 text-center text-sm text-muted">No files attached.</li><?php endif; ?>
          </ul>
        </div>

        <?php if ($history): ?>
        <div x-show="tab === 'history'" x-cloak class="p-4">
          <ul class="space-y-3 text-[13px]">
            <?php foreach ($history as $h): $ch = json_decode((string) $h['changes'], true) ?: []; ?>
              <li class="rounded-lg border border-theme px-3 py-2">
                <div class="flex flex-wrap items-center gap-2 text-xs"><span class="font-medium"><?= e($h['user_name'] ?? 'System') ?></span><span class="badge badge-slate before:hidden"><?= e(str_replace('record.', '', $h['action'])) ?></span><span class="text-faint"><?= e(fmt_datetime($h['created_at'])) ?> · <?= e($h['ip']) ?></span></div>
                <?php if ($h['action'] === 'record.updated'): ?>
                  <dl class="mt-1.5 grid gap-1 text-xs">
                    <?php foreach ($ch as $field => $d): if (!is_array($d) || !array_key_exists('from', $d)) continue; $fd = $fields[$field] ?? ['label' => $field, 'type' => 'text', 'name' => $field]; ?>
                      <div class="flex flex-wrap gap-1.5"><dt class="text-muted"><?= e($fd['label']) ?>:</dt><dd><span class="text-faint line-through"><?= e(str_limit(FieldRenderer::plain($fd, $d['from']), 60)) ?: '—' ?></span> → <span><?= e(str_limit(FieldRenderer::plain($fd, $d['to']), 60)) ?: '—' ?></span></dd></div>
                    <?php endforeach; ?>
                  </dl>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </section>
    </div>

    <!-- Details sidebar -->
    <aside class="space-y-5">
      <?php foreach ($groups as $gname => $gfields): ?>
      <section class="card">
        <div class="card-header"><h2 class="card-title"><?= e($gname) ?></h2></div>
        <dl class="space-y-0.5 p-2">
          <?php foreach ($gfields as $n => $f):
            $editable = $canEdit && empty($f['readonly']) && empty($f['system']) && in_array($f['type'], $inlineOk, true) && !($isDoc && in_array($n, ['customer_id', 'currency', 'number'], true));
            $val = $record[$n] ?? '';
            if ($f['type'] === 'datetime') $val = to_local_input((string) $val);
            if ($f['type'] === 'duration' && $val !== '') $val = intdiv((int) $val, 60) . ':' . str_pad((string) ((int) $val % 60), 2, '0', STR_PAD_LEFT);
          ?>
            <div class="grid grid-cols-[7.5rem_minmax(0,1fr)] items-start gap-2 rounded-lg px-2 py-1.5" x-data="inlineField(<?= ej(['url' => url('/x/r/' . $entity . '/' . $id), 'field' => $n, 'type' => $f['type'], 'value' => $val, 'editable' => $editable, 'reload' => in_array($n, ['status', 'stage_id', 'pipeline_id', 'priority', 'first_name', 'last_name'], true)]) ?>)">
              <dt class="pt-1 text-xs text-muted"><?= e($f['label']) ?></dt>
              <dd class="min-w-0 text-[13px]">
                <div x-show="!editing" x-ref="display" @click="start()" class="<?= $editable ? 'inline-field' : 'py-1' ?> break-words"><?= FieldRenderer::html($f, $record, $svc) ?></div>
                <?php if ($editable): ?>
                <div x-show="editing" x-cloak>
                  <?php if (in_array($f['type'], ['select', 'user', 'currency', 'tax', 'stage', 'bool'], true)): ?>
                    <select class="select input-sm" x-model="value" data-inline-input @change="save()" @keydown.escape="cancel()" @blur="editing = false">
                      <option value=""><?= $f['type'] === 'user' ? 'Unassigned' : '—' ?></option>
                      <?php foreach (LookupService::fieldOptions($f) as $o): ?><option value="<?= e($o['value']) ?>"><?= e(($o['group'] ?? '') ? $o['group'] . ' · ' . $o['label'] : $o['label']) ?></option><?php endforeach; ?>
                    </select>
                  <?php elseif ($f['type'] === 'textarea'): ?>
                    <textarea class="textarea text-[13px]" rows="4" x-model="value" data-inline-input @keydown.ctrl.enter="save()" @keydown.meta.enter="save()" @keydown.escape="cancel()"></textarea>
                    <div class="mt-1.5 flex justify-end gap-1.5"><button type="button" class="btn btn-ghost btn-xs" @click="cancel()">Cancel</button><button type="button" class="btn btn-primary btn-xs" @click="save()" :disabled="saving">Save</button></div>
                  <?php else: $itype = ['date' => 'date', 'datetime' => 'datetime-local', 'number' => 'number', 'decimal' => 'number', 'money' => 'number', 'percent' => 'number', 'email' => 'email', 'url' => 'url', 'phone' => 'tel'][$f['type']] ?? 'text'; ?>
                    <input type="<?= $itype ?>" <?= in_array($itype, ['number'], true) ? 'step="any"' : '' ?> class="input input-sm" x-model="value" data-inline-input @keydown.enter.prevent="save()" @keydown.escape="cancel()" @blur="save()">
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </section>
      <?php endforeach; ?>
      <section class="card p-4 text-xs text-muted">
        <p>Created <?= e(fmt_datetime($record['created_at'])) ?><?= !empty($record['_labels']['created_by']) ? ' by ' . e($record['_labels']['created_by']) : '' ?></p>
        <p class="mt-1">Last updated <?= e(time_ago($record['updated_at'])) ?></p>
      </section>
    </aside>
  </div>
</div>

<?php if ($entity === 'leads' && empty($record['converted_at']) && $canEdit): ?>
<div x-data="{ open: false, deal: true, busy: false }" @open-convert.window="open = true" @keydown.escape.window="open = false">
  <div x-cloak x-show="open" class="overlay" @click="open = false"></div>
  <form x-cloak x-show="open" x-transition class="modal p-5" @submit.prevent="busy = true; NX.api('<?= e(url('/r/leads/' . $id . '/convert')) ?>', { body: new FormData($el) }).then(NX.handle).catch(NX.fail).finally(() => busy = false)">
    <?= csrf_field() ?>
    <h3 class="text-base font-semibold">Convert lead</h3>
    <p class="mt-1 text-sm text-muted">Creates a customer and contact from <?= e($record['name']) ?>, moves notes and files, and optionally opens a deal.</p>
    <div class="mt-4 space-y-3.5">
      <div><label class="label">Customer</label><?= View::partial('partials/field_input', ['f' => ['name' => 'customer_id', 'type' => 'relation', 'relation' => 'customers', 'label' => 'Customer', 'help' => 'Leave empty to create “' . ($record['company_name'] ?: $record['name']) . '” as a new customer.'], 'value' => '', 'display' => null]) ?></div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="create_deal" value="1" x-model="deal">Create a deal</label>
      <div x-show="deal" class="grid gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2"><label class="label">Deal name</label><input name="deal_name" class="input" value="<?= e(($record['company_name'] ?: $record['name']) . ' — New deal') ?>"></div>
        <div><label class="label">Amount</label><input name="deal_amount" type="number" step="0.01" class="input" value="<?= e((string) $record['estimated_value']) ?>"></div>
        <div><label class="label">Expected close</label><input name="expected_close" type="date" class="input" value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>"></div>
        <div class="sm:col-span-2"><label class="label">Pipeline</label><select name="pipeline_id" class="select"><?php foreach (LookupService::pipelines() as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      </div>
    </div>
    <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-secondary" @click="open = false">Cancel</button><button type="submit" class="btn btn-primary" :disabled="busy">Convert lead</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($isDoc && $canEdit): $toEmail = $doc['contact']['email'] ?? ($doc['customer']['email'] ?? ''); $company = $doc['company']; ?>
<div x-data="{ open: false }" @open-email.window="open = true" @keydown.escape.window="open = false">
  <div x-cloak x-show="open" class="overlay" @click="open = false"></div>
  <form x-cloak x-show="open" x-transition class="modal p-5" method="post" action="<?= e(url('/documents/' . $docType . '/' . $id . '/email')) ?>" data-ajax>
    <?= csrf_field() ?>
    <h3 class="text-base font-semibold">Send <?= $docType === 'quote' ? 'quotation' : 'invoice' ?></h3>
    <div class="mt-4 space-y-3">
      <div><label class="label">To</label><input name="to" type="email" class="input" value="<?= e($toEmail) ?>" required></div>
      <div><label class="label">Subject</label><input name="subject" class="input" value="<?= e(($docType === 'quote' ? 'Quotation ' : 'Invoice ') . $record['number'] . ' from ' . ($company['name'] ?? '')) ?>"></div>
      <div><label class="label">Message</label><textarea name="message" rows="5" class="textarea"><?= e("Hello" . (!empty($doc['contact']['first_name']) ? ' ' . $doc['contact']['first_name'] : '') . ",\n\nPlease find " . ($docType === 'quote' ? 'our quotation' : 'invoice') . ' ' . $record['number'] . ' for ' . money($record['total'], $record['currency']) . " attached.\n\nRegards,\n" . user()['name']) ?></textarea></div>
      <p class="text-xs text-faint">The PDF is attached and the e-mail includes a secure link to view<?= $docType === 'quote' ? ' and accept' : '' ?> online.</p>
    </div>
    <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-secondary" @click="open = false">Cancel</button><button type="submit" class="btn btn-primary"><?= icon('send') ?>Send</button></div>
  </form>
</div>
<?php endif; ?>

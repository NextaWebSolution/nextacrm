<?php defined('NX_ROOT') || exit; $canEdit = can('sequences', $seq['id'] ? 'edit' : 'create'); ?>
<div class="mx-auto max-w-4xl" x-data="sequenceEditor(<?= ej(['url' => url('/sequences/' . (int) $seq['id']), 'seq' => ['name' => $seq['name'], 'entity' => $seq['entity'], 'is_active' => (bool) $seq['is_active'], 'stop_on_reply' => (bool) $seq['stop_on_reply']], 'steps' => array_map(fn ($s) => ['delay_days' => (int) $s['delay_days'], 'subject' => $s['subject'], 'body' => $s['body']], $steps)]) ?>)">
  <div class="mb-5 flex flex-wrap items-center gap-3">
    <h1 class="flex-1 text-xl font-semibold tracking-tight"><?= $seq['id'] ? e($seq['name']) : 'New sequence' ?></h1>
    <?php if ($seq['id'] && can('sequences', 'delete')): ?><button class="btn btn-danger-ghost" data-action="<?= e(url('/sequences/' . (int) $seq['id'])) ?>" data-method="DELETE" data-confirm="Delete this sequence and all enrolments?" data-danger><?= icon('trash') ?>Delete</button><?php endif; ?>
    <?php if ($canEdit): ?><button class="btn btn-primary" @click="save()" :disabled="busy"><?= icon('save') ?>Save</button><?php endif; ?>
  </div>
  <section class="card grid gap-4 p-4 sm:grid-cols-2">
    <div><label class="label" for="sname">Name</label><input id="sname" class="input" x-model="seq.name" placeholder="New lead nurture"></div>
    <div><label class="label" for="sentity">For</label><select id="sentity" class="select" x-model="seq.entity"><?php foreach (App\Services\SequenceService::ENTITIES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" x-model="seq.is_active">Active</label>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" x-model="seq.stop_on_reply">Stop when they reply (needs an inbound mailbox)</label>
  </section>
  <div class="mt-5 space-y-3">
    <template x-for="(s, i) in steps" :key="i">
      <section class="card">
        <div class="card-header"><h2 class="card-title" x-text="'Step ' + (i + 1)"></h2>
          <div class="flex items-center gap-2 text-sm"><span class="text-muted" x-text="i === 0 ? 'Send after' : 'Wait'"></span><input type="number" min="0" max="365" class="input input-sm w-20" x-model.number="s.delay_days" aria-label="Days"><span class="text-muted">days</span>
            <button type="button" class="btn btn-ghost btn-icon btn-sm text-faint hover:text-red-600" @click="steps.splice(i, 1)" x-show="steps.length > 1" aria-label="Remove step"><?= icon('trash', 'size-3.5') ?></button></div>
        </div>
        <div class="space-y-3 p-4">
          <div><label class="label">Subject</label><input class="input" x-model="s.subject"></div>
          <div><label class="label">Message</label><textarea class="textarea" rows="6" x-model="s.body"></textarea></div>
        </div>
      </section>
    </template>
    <?php if ($canEdit): ?><button type="button" class="btn btn-secondary" @click="steps.push({ delay_days: 3, subject: '', body: '' })"><?= icon('plus') ?>Add step</button><?php endif; ?>
    <p class="text-xs text-muted">Placeholders: {{name}}, {{first_name}}, {{company_name}}, {{email}}, {{user.name}}, {{today}}. An unsubscribe link and your company name are added automatically.</p>
  </div>
  <?php if ($seq['id']): ?>
  <section class="card mt-6"><div class="card-header"><h2 class="card-title">Enrolled (<?= count($enrolled) ?>)</h2></div>
    <ul class="divide-theme text-[13px]">
      <?php foreach ($enrolled as $en): ?><li class="flex items-center gap-3 px-4 py-2"><a class="min-w-0 flex-1 truncate link" href="<?= e(url('/r/' . $en['entity'] . '/' . $en['record_id'])) ?>"><?= e($en['email']) ?></a><span class="text-xs text-muted">step <?= (int) $en['current_step'] ?></span><?= badge(ucfirst($en['status']), ['active' => 'blue', 'completed' => 'green', 'replied' => 'violet', 'unsubscribed' => 'amber'][$en['status']] ?? 'slate') ?>
        <span class="w-28 text-right text-xs text-faint"><?= $en['status'] === 'active' && $en['next_run_at'] ? 'next ' . e(fmt_datetime($en['next_run_at'])) : '' ?></span>
        <?php if ($en['status'] === 'active' && can('sequences', 'edit')): ?><button class="btn btn-ghost btn-xs" data-action="<?= e(url('/sequences/enrollments/' . $en['id'] . '/stop')) ?>">Stop</button><?php endif; ?></li><?php endforeach; ?>
      <?php if (!$enrolled): ?><li class="px-4 py-6 text-center text-muted">Nobody enrolled yet.</li><?php endif; ?>
    </ul></section>
  <?php endif; ?>
</div>

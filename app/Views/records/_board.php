<?php defined('NX_ROOT') || exit;
use App\Services\FieldRenderer;
use App\Services\LookupService;
$def = $svc->def;
$fields = $def['fields'];
$amountField = null;
foreach ($fields as $n => $f) { if (($f['aggregate'] ?? null) === 'sum') { $amountField = $n; break; } }
$dateField = null;
foreach (['due_date', 'expected_close', 'follow_up_date', 'end_date', 'start_date', 'resolution_due', 'expense_date'] as $df) { if (isset($fields[$df])) { $dateField = $df; break; } }
$colorMap = ['slate' => 'bg-zinc-400', 'blue' => 'bg-sky-500', 'violet' => 'bg-violet-500', 'amber' => 'bg-amber-500', 'green' => 'bg-emerald-500', 'red' => 'bg-red-500', 'teal' => 'bg-teal-500', 'rose' => 'bg-rose-500', 'indigo' => 'bg-indigo-500'];
$canEdit = can($def['module'], 'edit');
?>
<div x-data="board(<?= ej(['entity' => $entity, 'canEdit' => $canEdit]) ?>)">
  <div class="kanban -mx-3 px-3 sm:mx-0 sm:px-0">
    <?php foreach ($boardColumns as $col): $cards = $boardCards[$col['value']] ?? []; $c = $boardCounts[$col['value']] ?? ['count' => count($cards), 'total' => 0]; ?>
      <section class="kanban-col" data-col="<?= e($col['value']) ?>" aria-label="<?= e($col['label']) ?>">
        <header class="flex items-center gap-2 px-3 pb-1.5 pt-3">
          <span class="size-2 rounded-full <?= $colorMap[$col['color']] ?? 'bg-zinc-400' ?>"></span>
          <h3 class="truncate text-[13px] font-semibold"><?= e($col['label']) ?></h3>
          <span class="text-xs text-faint" data-count><?= (int) $c['count'] ?></span>
          <?php if ($amountField && $c['total'] > 0): ?><span class="ml-auto text-xs font-medium tabular-nums text-muted"><?= e(money($c['total'])) ?></span><?php endif; ?>
        </header>
        <?php if (!empty($col['meta'])): ?><div class="px-3 pb-1 text-[11px] text-faint"><?= (int) $col['meta']['probability'] ?>% probability</div><?php endif; ?>
        <div class="kanban-cards">
          <?php foreach ($cards as $row): $href = url('/r/' . $entity . '/' . $row['id']); ?>
            <article class="kanban-card" data-id="<?= (int) $row['id'] ?>" data-href="<?= e($href) ?>">
              <div class="flex items-start justify-between gap-2">
                <a href="<?= e($href) ?>" class="line-clamp-2 text-[13px] font-medium leading-snug hover:underline"><?= e($row['_title']) ?></a>
                <?php if (isset($fields['priority']) && !empty($row['priority']) && $boardField !== 'priority'): ?><?= badge($row['priority']) ?><?php endif; ?>
              </div>
              <?php $sub = $def['subtitle'] ?? null; if ($sub && !empty($row[$sub])): ?><p class="mt-0.5 truncate text-xs text-muted"><?= e($row['_labels'][$sub] ?? $row[$sub]) ?></p><?php endif; ?>
              <?php if ($amountField && (float) ($row[$amountField] ?? 0) > 0): ?><p class="mt-2 text-[13px] font-semibold tabular-nums"><?= e(money($row[$amountField], $row['currency'] ?? null)) ?></p><?php endif; ?>
              <?php if (!empty($row['tags'])): ?><div class="mt-2"><?= FieldRenderer::html($fields['tags'], $row, $svc) ?></div><?php endif; ?>
              <div class="mt-2.5 flex items-center justify-between gap-2 text-xs text-muted">
                <span class="truncate"><?= $dateField && !empty($row[$dateField]) ? icon('calendar', 'size-3.5 inline -mt-0.5 mr-1 text-faint') . FieldRenderer::html($fields[$dateField], $row, $svc) : '' ?></span>
                <?php if (!empty($row['assigned_to'])): $u = LookupService::users([(int) $row['assigned_to']])[(int) $row['assigned_to']] ?? null; if ($u): ?><?= FieldRenderer::avatar($u['name'], $u['hue'], 'size-6 text-[9px]') ?><?php endif; endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <?php if (can($def['module'], 'create') && empty($def['document'])): ?>
          <button type="button" class="m-1.5 mt-0 flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-[color-mix(in_oklab,var(--text)_5%,transparent)] hover:text-[var(--text)]" @click="$dispatch('open-form', { entity: '<?= e($entity) ?>', quick: true, prefill: <?= ej(array_filter([$boardField => $col['value'], 'pipeline_id' => $pipelineId ? (string) $pipelineId : null])) ?> })"><?= icon('plus', 'size-3.5') ?>Add</button>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
  <?php if (!$boardTotal): ?><p class="mt-2 text-center text-xs text-faint">No records match. Use “Add” in a column to create one.</p><?php endif; ?>
</div>

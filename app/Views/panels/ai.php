<?php defined('NX_ROOT') || exit;
if (!App\Services\AiService::enabled()) return; ?>
<section class="card" x-data="aiAssist(<?= ej(['url' => url('/r/' . $entity . '/' . (int) $rec['id'] . '/ai')]) ?>)">
  <div class="card-header"><h2 class="card-title flex items-center gap-2"><?= icon('wand-sparkles', 'size-4 text-violet-600') ?>AI assistant</h2></div>
  <div class="space-y-2 p-4">
    <div class="flex flex-wrap gap-1.5">
      <?php foreach (App\Services\AiService::ACTIONS as $k => $l): ?><button type="button" class="btn btn-secondary btn-xs" :disabled="busy" @click="go('<?= $k ?>')"><?= e($l) ?></button><?php endforeach; ?>
    </div>
    <input class="input input-sm" x-model="extra" maxlength="400" placeholder="Optional: tone or focus (e.g. formal, mention the discount)" aria-label="Extra instruction">
    <p x-show="busy" x-cloak class="flex items-center gap-2 text-xs text-muted"><span class="size-3 animate-spin rounded-full border-2 border-current border-t-transparent"></span>Thinking…</p>
    <div x-show="text" x-cloak class="rounded-lg border border-theme bg-[var(--surface-2)] p-3">
      <p class="whitespace-pre-wrap text-[13px] leading-relaxed" x-text="text"></p>
      <div class="mt-2 flex gap-1"><button type="button" class="btn btn-ghost btn-xs" @click="copy()"><?= icon('copy', 'size-3.5') ?>Copy</button></div>
    </div>
    <p class="text-[11px] text-faint">AI output can be wrong — check before sending. Sensitive fields are never shared with the provider.</p>
  </div>
</section>

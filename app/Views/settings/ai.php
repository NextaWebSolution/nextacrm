<?php defined('NX_ROOT') || exit; $prov = App\Services\AiService::provider();
$key = fn ($p, $label) => '<div><label class="label" for="' . $p . '_key">' . e($label) . '</label><input id="' . $p . '_key" name="' . $p . '_key" type="password" autocomplete="off" class="input" placeholder="' . (!empty($has[$p]) ? '•••••••• saved — leave empty to keep' : '') . '">' . (!empty($has[$p]) ? '<label class="mt-1 flex items-center gap-1.5 text-xs text-muted"><input type="checkbox" class="checkbox" name="' . $p . '_clear" value="1">Remove saved key</label>' : '') . '</div>';
?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<form method="post" action="<?= e(url('/settings/ai')) ?>" class="space-y-5" x-data="{ p: <?= e(json_encode($prov)) ?> }">
  <?= csrf_field() ?>
  <div><h1 class="text-xl font-semibold tracking-tight">AI assistant</h1><p class="mt-1 text-sm text-muted">Summaries, next-step suggestions and reply drafts on leads, deals, customers, contacts, tickets and projects. Bring your own API key — you pay the provider directly.</p></div>
  <section class="card">
    <div class="card-header"><h2 class="card-title">Provider</h2><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="enabled" value="1" <?= setting('ai.enabled') ? 'checked' : '' ?>>Enabled</label></div>
    <div class="grid gap-4 p-4 sm:grid-cols-2">
      <div><label class="label" for="provider">Provider</label><select id="provider" name="provider" class="select" x-model="p"><?php foreach (App\Services\AiService::PROVIDERS as $k => $l): ?><option value="<?= $k ?>" <?= $k === $prov ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label class="label" for="daily_limit">Daily request limit (whole workspace)</label><input id="daily_limit" type="number" min="0" name="daily_limit" class="input" value="<?= (int) setting('ai.daily_limit', 200) ?>"></div>
      <template x-if="p === 'anthropic'"><div class="contents"><?= $key('anthropic', 'Anthropic API key') ?><div><label class="label" for="anthropic_model">Model</label><input id="anthropic_model" name="anthropic_model" class="input" value="<?= e((string) setting('ai.anthropic.model', '')) ?>" placeholder="<?= e(App\Services\AiService::DEFAULT_MODELS['anthropic']) ?>"></div></div></template>
      <template x-if="p === 'openai'"><div class="contents"><?= $key('openai', 'OpenAI API key') ?><div><label class="label" for="openai_model">Model</label><input id="openai_model" name="openai_model" class="input" value="<?= e((string) setting('ai.openai.model', '')) ?>" placeholder="<?= e(App\Services\AiService::DEFAULT_MODELS['openai']) ?>"></div></div></template>
      <template x-if="p === 'custom'"><div class="contents">
        <div><label class="label" for="custom_url">Base URL (OpenAI-compatible)</label><input id="custom_url" name="custom_url" class="input" value="<?= e((string) setting('ai.custom.url', '')) ?>" placeholder="https://llm.example.com/v1"></div>
        <div><label class="label" for="custom_model">Model</label><input id="custom_model" name="custom_model" class="input" value="<?= e((string) setting('ai.custom.model', '')) ?>" placeholder="llama3.1"></div>
        <?= $key('custom', 'API key (optional)') ?>
        <label class="mt-6 flex items-start gap-2 text-sm"><input type="checkbox" class="checkbox mt-0.5" name="custom_allow_private" value="1" <?= setting('ai.custom.allow_private') ? 'checked' : '' ?>><span>Allow a private/local address (e.g. Ollama on the same network). Only enable if you control that server.</span></label>
      </div></template>
    </div>
  </section>
  <section class="card"><div class="card-header"><h2 class="card-title">Privacy</h2></div>
    <div class="space-y-3 p-4 text-sm">
      <label class="flex items-start gap-2"><input type="checkbox" class="checkbox mt-0.5" name="include_contact" value="1" <?= setting('ai.include_contact') ? 'checked' : '' ?>><span>Include e-mail addresses and phone numbers in what is sent (off by default — they are masked).</span></label>
      <p class="flex items-start gap-2 text-xs text-muted"><?= icon('shield-check', 'size-4 shrink-0 text-emerald-600') ?>Only the record you are looking at is sent, and only if you can view it. Salary, PAN, bank details, passwords and tokens are never sent. Keys are encrypted at rest and never shown again.</p>
    </div></section>
  <section class="card p-4 text-sm"><p><strong><?= (int) $usage['n'] ?></strong> requests this month · <?= number_format((int) $usage['tin']) ?> input / <?= number_format((int) $usage['tout']) ?> output tokens · <?= (int) $today ?> today</p></section>
  <div class="flex gap-2"><button class="btn btn-primary" type="submit"><?= icon('save') ?>Save</button><button type="button" class="btn btn-secondary" data-action="<?= e(url('/settings/ai/test')) ?>"><?= icon('plug') ?>Test connection</button></div>
</form>
</div>

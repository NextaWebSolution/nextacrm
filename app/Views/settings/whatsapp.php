<?php defined('NX_ROOT') || exit; $p = (string) setting('wa.provider', ''); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="space-y-5">
<form method="post" action="<?= e(url('/settings/whatsapp')) ?>" class="space-y-5" x-data="{ p: '<?= e($p) ?>' }">
  <?= csrf_field() ?>
  <div><h1 class="text-xl font-semibold tracking-tight">WhatsApp</h1><p class="mt-1 text-sm text-muted">Send WhatsApp messages from leads, contacts, deals, invoices, quotations and tickets, and from workflows.</p></div>
  <section class="card"><div class="grid gap-4 p-4 sm:grid-cols-2">
    <div><label class="label" for="provider">Provider</label><select id="provider" name="provider" class="select" x-model="p"><option value="">Off</option><?php foreach (App\Services\WhatsAppService::PROVIDERS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="default_cc">Default country code</label><input id="default_cc" name="default_cc" class="input" value="<?= e((string) setting('wa.default_cc', '91')) ?>"></div>
  </div></section>
  <section class="card" x-show="p === 'meta'" x-cloak><div class="card-header"><h2 class="card-title">Meta WhatsApp Cloud API</h2></div><div class="grid gap-4 p-4 sm:grid-cols-2">
    <div><label class="label" for="meta_phone_id">Phone number ID</label><input id="meta_phone_id" name="meta_phone_id" class="input" value="<?= e((string) setting('wa.meta.phone_id', '')) ?>"></div>
    <div><label class="label" for="meta_token">Permanent access token</label><input id="meta_token" name="meta_token" type="password" autocomplete="off" class="input" placeholder="<?= $has['meta'] ? '•••••••• saved — leave empty to keep' : '' ?>"></div>
    <div><label class="label" for="meta_language">Template language</label><input id="meta_language" name="meta_language" class="input" value="<?= e((string) setting('wa.meta.language', 'en')) ?>"></div>
  </div></section>
  <section class="card" x-show="p === 'twilio'" x-cloak><div class="card-header"><h2 class="card-title">Twilio</h2></div><div class="grid gap-4 p-4 sm:grid-cols-2">
    <div><label class="label" for="twilio_sid">Account SID</label><input id="twilio_sid" name="twilio_sid" class="input" value="<?= e((string) setting('wa.twilio.sid', '')) ?>"></div>
    <div><label class="label" for="twilio_token">Auth token</label><input id="twilio_token" name="twilio_token" type="password" autocomplete="off" class="input" placeholder="<?= $has['twilio'] ? '•••••••• saved — leave empty to keep' : '' ?>"></div>
    <div><label class="label" for="twilio_from">WhatsApp sender number</label><input id="twilio_from" name="twilio_from" class="input" value="<?= e((string) setting('wa.twilio.from', '')) ?>" placeholder="+14155238886"></div>
  </div></section>
  <section class="card" x-show="p === 'gupshup'" x-cloak><div class="card-header"><h2 class="card-title">Gupshup</h2></div><div class="grid gap-4 p-4 sm:grid-cols-2">
    <div><label class="label" for="gupshup_api_key">API key</label><input id="gupshup_api_key" name="gupshup_api_key" type="password" autocomplete="off" class="input" placeholder="<?= $has['gupshup'] ? '•••••••• saved — leave empty to keep' : '' ?>"></div>
    <div><label class="label" for="gupshup_source">Source number</label><input id="gupshup_source" name="gupshup_source" class="input" value="<?= e((string) setting('wa.gupshup.source', '')) ?>"></div>
    <div><label class="label" for="gupshup_app">App name</label><input id="gupshup_app" name="gupshup_app" class="input" value="<?= e((string) setting('wa.gupshup.app', '')) ?>"></div>
  </div></section>
  <div class="flex justify-end"><button class="btn btn-primary" type="submit"><?= icon('save') ?>Save</button></div>
</form>
<?php if ($p): ?>
<form method="post" action="<?= e(url('/settings/whatsapp/test')) ?>" data-ajax class="card flex flex-wrap items-end gap-3 p-4"><?= csrf_field() ?><div class="min-w-0 flex-1"><label class="label" for="to">Send a test message to</label><input id="to" name="to" class="input" placeholder="+91 98765 43210" required></div><button class="btn btn-secondary" type="submit"><?= icon('send') ?>Send test</button></form>
<?php endif; ?>
<section class="card"><div class="card-header"><h2 class="card-title">Recent messages</h2></div>
  <ul class="divide-theme text-[13px]"><?php foreach ($log as $m): ?><li class="flex items-center gap-3 px-4 py-2"><span class="w-32 shrink-0 font-mono text-xs">+<?= e($m['to_number']) ?></span><span class="min-w-0 flex-1 truncate"><?= e($m['body']) ?><?php if ($m['error']): ?><span class="block text-xs text-red-600"><?= e($m['error']) ?></span><?php endif; ?></span><?= badge($m['status'], $m['status'] === 'sent' ? 'green' : ($m['status'] === 'failed' ? 'red' : 'slate')) ?><span class="w-20 text-right text-xs text-faint"><?= e(time_ago($m['created_at'])) ?></span></li><?php endforeach; ?>
  <?php if (!$log): ?><li class="px-4 py-6 text-center text-muted">No messages yet.</li><?php endif; ?></ul></section>
</div>
</div>

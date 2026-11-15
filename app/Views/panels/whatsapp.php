<?php defined('NX_ROOT') || exit;
if (!App\Services\WhatsAppService::enabled() || !App\Core\Permissions::can($svc->module, 'edit', $rec)) return;
$phone = App\Services\WhatsAppService::phoneFor($rec);
$name = $rec['first_name'] ?? $rec['requester_name'] ?? $rec['name'] ?? '';
$templates = ['Follow-up' => "Hi $name, just following up on our conversation. Let me know a good time to talk."];
if ($entity === 'invoices') {
    $link = !empty($rec['public_token']) && $rec['status'] !== 'Draft' ? App\Services\SalesService::publicUrl('invoice', App\Core\App::db()->find('invoices', (int) $rec['id'])) : '';
    $templates['Payment reminder'] = 'Hi, a reminder that invoice ' . $rec['number'] . ' for ' . money((float) $rec['balance'], $rec['currency']) . ' is due on ' . fmt_date($rec['due_date']) . '.' . ($link ? " View and pay: $link" : '');
}
if ($entity === 'quotes' && !empty($rec['public_token'])) {
    $templates['Send quotation'] = 'Hi, here is quotation ' . $rec['number'] . ': ' . App\Services\SalesService::publicUrl('quote', App\Core\App::db()->find('quotes', (int) $rec['id']));
}
?>
<section class="card" x-data="{ open: false, msg: '' }">
  <div class="card-header"><h2 class="card-title flex items-center gap-2"><?= icon('message-circle', 'size-4 text-emerald-600') ?>WhatsApp</h2><button type="button" class="btn btn-ghost btn-xs" @click="open = !open" x-text="open ? 'Close' : 'Send'"></button></div>
  <form x-show="open" x-cloak method="post" action="<?= e(url('/r/' . $entity . '/' . (int) $rec['id'] . '/whatsapp')) ?>" data-ajax data-reset class="space-y-2 p-4">
    <?= csrf_field() ?>
    <input name="to" class="input input-sm" value="<?= e($phone) ?>" placeholder="+91 98765 43210" aria-label="WhatsApp number">
    <div class="flex flex-wrap gap-1"><?php foreach ($templates as $tl => $tt): ?><button type="button" class="btn btn-ghost btn-xs" @click="msg = <?= e(json_encode($tt)) ?>"><?= e($tl) ?></button><?php endforeach; ?></div>
    <textarea name="message" rows="4" class="textarea text-[13px]" x-model="msg" placeholder="Message…" required></textarea>
    <details class="text-xs text-muted"><summary class="cursor-pointer">Use an approved template (Meta)</summary><input name="template" class="input input-sm mt-1" placeholder="template_name — message becomes the variables separated by |"></details>
    <button type="submit" class="btn btn-primary btn-sm w-full"><?= icon('send') ?>Send on WhatsApp</button>
  </form>
</section>

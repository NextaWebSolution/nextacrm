<?php defined('NX_ROOT') || exit;
$hook = fn ($g) => full_url('/hooks/pay/' . $g . '/' . $cid);
$secret = fn ($g, $k, $label) => '<div><label class="label" for="' . $g . '_' . $k . '">' . e($label) . '</label><input id="' . $g . '_' . $k . '" name="' . $g . '_' . $k . '" type="password" autocomplete="off" class="input" placeholder="' . (!empty($has[$g][$k]) ? '•••••••• saved — leave empty to keep' : '') . '"></div>';
?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<form method="post" action="<?= e(url('/settings/payments')) ?>" class="space-y-5">
  <?= csrf_field() ?>
  <div><h1 class="text-xl font-semibold tracking-tight">Online payments</h1><p class="mt-1 text-sm text-muted">Let customers pay invoices from the public invoice link and the client portal. Payments are recorded automatically after the gateway confirms them.</p></div>

  <section class="card">
    <div class="card-header"><h2 class="card-title">Razorpay</h2><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="razorpay_enabled" value="1" <?= setting('pay.razorpay.enabled') ? 'checked' : '' ?>>Enabled</label></div>
    <div class="grid gap-4 p-4 sm:grid-cols-2">
      <div><label class="label" for="razorpay_key_id">Key ID</label><input id="razorpay_key_id" name="razorpay_key_id" class="input" value="<?= e((string) setting('pay.razorpay.key_id', '')) ?>" placeholder="rzp_live_…"></div>
      <?= $secret('razorpay', 'key_secret', 'Key secret') ?>
      <?= $secret('razorpay', 'webhook_secret', 'Webhook secret') ?>
      <div><label class="label">Webhook URL (event: payment_link.paid)</label><div class="flex gap-2"><input class="input font-mono text-xs" readonly value="<?= e($hook('razorpay')) ?>" id="hook-rzp"><button type="button" class="btn btn-secondary" data-copy="#hook-rzp"><?= icon('copy') ?></button></div></div>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h2 class="card-title">Stripe</h2><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="stripe_enabled" value="1" <?= setting('pay.stripe.enabled') ? 'checked' : '' ?>>Enabled</label></div>
    <div class="grid gap-4 p-4 sm:grid-cols-2">
      <?= $secret('stripe', 'secret_key', 'Secret key (sk_live_… or a restricted key)') ?>
      <?= $secret('stripe', 'webhook_secret', 'Webhook signing secret (whsec_…)') ?>
      <div class="sm:col-span-2"><label class="label">Webhook URL (event: checkout.session.completed)</label><div class="flex gap-2"><input class="input font-mono text-xs" readonly value="<?= e($hook('stripe')) ?>" id="hook-stripe"><button type="button" class="btn btn-secondary" data-copy="#hook-stripe"><?= icon('copy') ?></button></div></div>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h2 class="card-title">PayPal</h2><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="paypal_enabled" value="1" <?= setting('pay.paypal.enabled') ? 'checked' : '' ?>>Enabled</label></div>
    <div class="grid gap-4 p-4 sm:grid-cols-2">
      <div><label class="label" for="paypal_client_id">Client ID</label><input id="paypal_client_id" name="paypal_client_id" class="input" value="<?= e((string) setting('pay.paypal.client_id', '')) ?>"></div>
      <?= $secret('paypal', 'client_secret', 'Client secret') ?>
      <div><label class="label" for="paypal_webhook_id">Webhook ID</label><input id="paypal_webhook_id" name="paypal_webhook_id" class="input" value="<?= e((string) setting('pay.paypal.webhook_id', '')) ?>"></div>
      <label class="mt-6 flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="paypal_sandbox" value="1" <?= setting('pay.paypal.sandbox') ? 'checked' : '' ?>>Sandbox mode (testing)</label>
      <div class="sm:col-span-2"><label class="label">Webhook URL (event: PAYMENT.CAPTURE.COMPLETED)</label><div class="flex gap-2"><input class="input font-mono text-xs" readonly value="<?= e($hook('paypal')) ?>" id="hook-pp"><button type="button" class="btn btn-secondary" data-copy="#hook-pp"><?= icon('copy') ?></button></div></div>
    </div>
  </section>
  <p class="flex items-start gap-2 text-xs text-muted"><?= icon('shield-check', 'size-4 shrink-0 text-emerald-600') ?>Keys are encrypted at rest and never shown again. Customers pay on the provider's own checkout page, so card details never touch this server. A payment is recorded only after a signed confirmation, once, for the exact amount requested.</p>
  <div class="flex justify-end"><button class="btn btn-primary" type="submit"><?= icon('save') ?>Save</button></div>
</form>
</div>

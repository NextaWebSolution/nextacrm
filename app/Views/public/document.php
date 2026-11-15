<?php defined('NX_ROOT') || exit; $isQuote = $type === 'quote'; ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e(($isQuote ? 'Quotation ' : 'Invoice ') . $doc['number'] . ' · ' . ($company['name'] ?? '')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"><script defer src="<?= e(asset('js/app.js')) ?>"></script><script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="min-h-screen bg-zinc-100 py-6 sm:py-10" style="--brand: <?= e($company['brand_color'] ?? '#4f46e5') ?>">
<div class="mx-auto max-w-4xl px-3">
  <?php foreach ($flash as $f): ?><div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $f['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800' ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
  <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-zinc-600"><?= e($company['name'] ?? '') ?> shared this <?= $isQuote ? 'quotation' : 'invoice' ?> with you.</p>
    <a href="<?= e(url('/p/' . $type . '/' . $token . '/pdf')) ?>" class="btn btn-secondary bg-white"><?= icon('download') ?>Download PDF</a>
  </div>
  <?php if (!$isQuote && in_array($doc['status'], App\Services\Payments\PaymentService::PAYABLE, true) && (float) $doc['balance'] > 0 && !empty($gateways)): ?>
    <section class="mb-4 rounded-xl border border-zinc-200 bg-white p-5 text-zinc-900">
      <div class="flex flex-wrap items-center gap-3">
        <div class="min-w-0 flex-1"><p class="text-sm text-zinc-500">Balance due</p><p class="text-2xl font-semibold"><?= e(money((float) $doc['balance'], $doc['currency'])) ?></p></div>
        <?php foreach ($gateways as $g): ?>
          <form method="post" action="<?= e(url('/p/invoice/' . $token . '/pay')) ?>"><?= csrf_field() ?><input type="hidden" name="gateway" value="<?= e($g->key()) ?>"><button class="btn btn-primary" type="submit"><?= icon('credit-card') ?>Pay with <?= e($g->label()) ?></button></form>
        <?php endforeach; ?>
      </div>
      <p class="mt-2 flex items-center gap-1.5 text-xs text-zinc-500"><?= icon('lock', 'size-3.5') ?>You'll complete the payment on the provider's secure page.</p>
    </section>
  <?php endif; ?>
  <?= App\Core\View::partial('documents/_preview', get_defined_vars()) ?>
  <?php if ($isQuote && $doc['status'] === 'Sent'): ?>
    <section class="mt-5 rounded-xl border border-zinc-200 bg-white p-5 text-zinc-900">
      <h2 class="font-semibold">Accept and sign this quotation</h2>
      <p class="mt-1 text-sm text-zinc-500">Your signature, name, the time and your IP address are recorded with a fingerprint of this quotation.</p>
      <form method="post" action="<?= e(url('/p/quote/' . $token . '/respond')) ?>" class="mt-4 space-y-3">
        <?= csrf_field() ?><input type="hidden" name="decision" value="accept">
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label class="label text-zinc-600" for="name">Full name</label><input id="name" name="name" class="input bg-white text-zinc-900" required maxlength="150" autocomplete="name"></div>
          <div><label class="label text-zinc-600" for="email">E-mail (optional)</label><input id="email" name="email" type="email" class="input bg-white text-zinc-900" autocomplete="email"></div>
        </div>
        <?= App\Core\View::partial('partials/signature_pad') ?>
        <label class="flex items-start gap-2 text-sm text-zinc-600"><input type="checkbox" class="checkbox mt-0.5" required>I agree to the terms of this quotation and that my electronic signature is legally binding.</label>
        <button type="submit" class="btn btn-primary"><?= icon('pen-tool') ?>Accept &amp; sign</button>
      </form>
      <form method="post" action="<?= e(url('/p/quote/' . $token . '/respond')) ?>" class="mt-4 border-t border-zinc-100 pt-4">
        <?= csrf_field() ?><input type="hidden" name="decision" value="decline">
        <button type="submit" class="btn btn-ghost btn-sm text-zinc-500" data-confirm="Decline this quotation?">Decline this quotation</button>
      </form>
    </section>
  <?php elseif ($isQuote && $doc['status'] === 'Accepted'): ?>
    <section class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
      <p>Accepted by <strong><?= e($doc['accepted_by_name']) ?></strong> on <?= e(fmt_datetime((string) $doc['accepted_at'])) ?>.</p>
      <?php if (!empty($signature)): ?><img src="<?= e($signature['image']) ?>" alt="Signature of <?= e($signature['signer_name']) ?>" class="mt-2 h-16 rounded bg-white p-1"><?php endif; ?>
    </section>
  <?php endif; ?>
  <p class="mt-6 text-center text-xs text-zinc-400">Powered by <?= e((string) setting('app.name', 'NextaCRM')) ?></p>
</div>
</body></html>

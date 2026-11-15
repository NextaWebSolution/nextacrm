<?php defined('NX_ROOT') || exit; ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e('Contract · ' . $c['title'] . ' · ' . ($company['name'] ?? '')) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"><script defer src="<?= e(asset('js/app.js')) ?>"></script><script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="min-h-screen bg-zinc-100 py-6 text-zinc-900 sm:py-10" style="--brand: <?= e($company['brand_color'] ?? '#4f46e5') ?>">
<div class="mx-auto max-w-3xl px-3">
  <?php foreach ($flash as $f): ?><div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $f['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800' ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
  <article class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
    <p class="text-sm text-zinc-500"><?= e($company['name'] ?? '') ?> · Contract</p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight"><?= e($c['title']) ?></h1>
    <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
      <div><dt class="text-zinc-500">Client</dt><dd class="font-medium"><?= e($customer['name'] ?? '') ?></dd></div>
      <div><dt class="text-zinc-500">Type</dt><dd class="font-medium"><?= e($c['type'] ?: '—') ?></dd></div>
      <div><dt class="text-zinc-500">Value</dt><dd class="font-medium"><?= $c['value'] !== null ? e(money((float) $c['value'], $c['currency'] ?: ($company['currency'] ?? 'INR'))) : '—' ?></dd></div>
      <div><dt class="text-zinc-500">Start</dt><dd class="font-medium"><?= e(fmt_date($c['start_date'])) ?></dd></div>
      <div><dt class="text-zinc-500">End</dt><dd class="font-medium"><?= e(fmt_date($c['end_date'])) ?></dd></div>
    </dl>
    <?php if (!empty($c['description'])): ?><div class="mt-6 border-t border-zinc-100 pt-5 text-sm leading-relaxed"><?= nl2br_safe($c['description']) ?></div><?php endif; ?>
  </article>
  <?php if ($signature): ?>
    <section class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900">
      <p>Signed by <strong><?= e($signature['signer_name']) ?></strong> on <?= e(fmt_datetime($signature['signed_at'])) ?> (UTC record kept).</p>
      <img src="<?= e($signature['image']) ?>" alt="Signature of <?= e($signature['signer_name']) ?>" class="mt-2 h-16 rounded bg-white p-1">
    </section>
  <?php else: ?>
    <form method="post" action="<?= e(url('/p/contract/' . $token . '/sign')) ?>" class="mt-5 space-y-3 rounded-xl border border-zinc-200 bg-white p-5">
      <?= csrf_field() ?>
      <h2 class="font-semibold">Sign this contract</h2>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label text-zinc-600" for="name">Full name</label><input id="name" name="name" class="input bg-white text-zinc-900" required maxlength="150" autocomplete="name"></div>
        <div><label class="label text-zinc-600" for="email">E-mail</label><input id="email" name="email" type="email" class="input bg-white text-zinc-900" autocomplete="email"></div>
      </div>
      <?= App\Core\View::partial('partials/signature_pad') ?>
      <label class="flex items-start gap-2 text-sm text-zinc-600"><input type="checkbox" class="checkbox mt-0.5" required>I have read this contract and agree that my electronic signature is legally binding.</label>
      <button type="submit" class="btn btn-primary"><?= icon('pen-tool') ?>Sign contract</button>
    </form>
  <?php endif; ?>
  <p class="mt-6 text-center text-xs text-zinc-400">Powered by <?= e((string) setting('app.name', 'NextaCRM')) ?></p>
</div>
</body></html>

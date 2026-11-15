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
  <?= App\Core\View::partial('documents/_preview', get_defined_vars()) ?>
  <?php if ($isQuote && $doc['status'] === 'Sent'): ?>
    <form method="post" action="<?= e(url('/p/quote/' . $token . '/respond')) ?>" class="mt-5 rounded-xl border border-zinc-200 bg-white p-5" x-data="{ decision: 'accept' }">
      <?= csrf_field() ?>
      <h2 class="font-semibold text-zinc-900">Respond to this quotation</h2>
      <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
        <div><label class="label text-zinc-600" for="name">Your full name (acts as your acceptance signature)</label><input id="name" name="name" class="input bg-white text-zinc-900" placeholder="Full name"></div>
        <button type="submit" name="decision" value="accept" class="btn btn-primary"><?= icon('check') ?>Accept quotation</button>
        <button type="submit" name="decision" value="decline" class="btn btn-secondary bg-white text-zinc-700" data-confirm="Decline this quotation?">Decline</button>
      </div>
    </form>
  <?php elseif ($isQuote && $doc['status'] === 'Accepted'): ?>
    <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">Accepted by <?= e($doc['accepted_by_name']) ?> on <?= e(fmt_date(substr((string) $doc['accepted_at'], 0, 10))) ?>.</p>
  <?php endif; ?>
  <p class="mt-6 text-center text-xs text-zinc-400">Powered by <?= e((string) setting('app.name', 'NextaCRM')) ?></p>
</div>
</body></html>

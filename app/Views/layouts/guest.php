<?php defined('NX_ROOT') || exit;
$appName = (string) setting('app.name', 'NextaCRM');
$flash = App\Core\Session::takeFlash();
?><!doctype html>
<html lang="en" class="h-full" data-theme="system" style="--brand:#4f46e5">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e(($title ?? 'Sign in') . ' · ' . $appName) ?></title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<script defer src="<?= e(asset('js/app.js')) ?>"></script>
<script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="h-full antialiased">
<div class="grid min-h-full lg:grid-cols-[1fr_minmax(0,34rem)]">
  <aside class="relative hidden overflow-hidden bg-zinc-950 p-12 text-white lg:flex lg:flex-col">
    <div class="absolute inset-0 opacity-[.35] [background:radial-gradient(60rem_30rem_at_10%_0%,var(--brand),transparent_60%)]"></div>
    <div class="relative flex items-center gap-2.5"><span class="grid size-9 place-items-center rounded-lg bg-white/10 ring-1 ring-white/15"><?= icon('layers', 'size-5') ?></span><span class="text-lg font-semibold tracking-tight"><?= e($appName) ?></span></div>
    <div class="relative mt-auto max-w-md">
      <h2 class="text-3xl font-semibold leading-tight tracking-tight">Run your whole IT business from one place.</h2>
      <p class="mt-4 text-zinc-300">Leads, deals, projects, time, invoices, support and your team — connected, secure and fast.</p>
      <ul class="mt-8 space-y-3 text-sm text-zinc-300">
        <li class="flex items-center gap-3"><span class="text-emerald-400"><?= icon('circle-check') ?></span>Pipeline to project to invoice, without double entry</li>
        <li class="flex items-center gap-3"><span class="text-emerald-400"><?= icon('circle-check') ?></span>Role-based access, 2FA and full audit trail</li>
        <li class="flex items-center gap-3"><span class="text-emerald-400"><?= icon('circle-check') ?></span>Works on any device, light and dark</li>
      </ul>
    </div>
  </aside>
  <main class="flex items-center justify-center px-5 py-12 sm:px-10">
    <div class="w-full max-w-sm">
      <div class="mb-8 flex items-center gap-2.5 lg:hidden"><span class="grid size-9 place-items-center rounded-lg bg-primary-600 text-white"><?= icon('layers', 'size-5') ?></span><span class="text-lg font-semibold"><?= e($appName) ?></span></div>
      <?php foreach ($flash as $f): ?>
        <div class="mb-4 flex gap-2.5 rounded-lg border px-3.5 py-2.5 text-sm <?= $f['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300' ?>" role="alert"><?= icon($f['type'] === 'success' ? 'circle-check' : 'circle-exclamation-point', 'size-4.5 shrink-0 mt-px') ?><span><?= e($f['message']) ?></span></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </main>
</div>
</body>
</html>

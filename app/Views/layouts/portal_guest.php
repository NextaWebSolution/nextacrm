<?php defined('NX_ROOT') || exit; $appName = (string) setting('app.name', 'NextaCRM'); ?><!doctype html>
<html lang="en" class="h-full" data-theme="system">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e(($title ?? 'Client portal') . ' · ' . $appName) ?></title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<script defer src="<?= e(asset('js/app.js')) ?>"></script>
<script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="grid min-h-full place-items-center bg-[var(--bg)] px-4 py-10 antialiased">
<main class="w-full max-w-sm">
  <div class="mb-8 flex items-center gap-2.5"><span class="grid size-9 place-items-center rounded-lg bg-primary-600 text-white"><?= icon('door-open', 'size-5') ?></span><span class="text-lg font-semibold tracking-tight">Client portal</span></div>
  <?php foreach ($flash ?? [] as $f): ?><div class="mb-4 rounded-lg border px-3.5 py-2.5 text-sm <?= $f['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300' ?>" role="alert"><?= e($f['message']) ?></div><?php endforeach; ?>
  <?= $content ?>
  <p class="mt-10 text-center text-xs text-faint">Powered by <?= e($appName) ?></p>
</main>
</body></html>

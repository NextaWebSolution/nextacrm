<?php defined('NX_ROOT') || exit;
$path = App\Core\App::request()->path;
$tabs = ['/portal' => ['house', 'Overview'], '/portal/invoices' => ['receipt', 'Invoices'], '/portal/quotes' => ['file-text', 'Quotations'], '/portal/projects' => ['folder-kanban', 'Projects'], '/portal/tickets' => ['life-buoy', 'Support'], '/portal/files' => ['files', 'Documents']];
$active = fn ($href) => $href === '/portal' ? $path === '/portal' : str_starts_with($path, $href);
?><!doctype html>
<html lang="en" class="h-full" data-theme="system" style="--brand:<?= e($company['brand_color'] ?? '#4f46e5') ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('/')) ?>">
<title><?= e(($title ?? 'Portal') . ' · ' . $company['name']) ?></title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<script defer src="<?= e(asset('js/app.js')) ?>"></script>
<script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="min-h-full bg-[var(--bg)] antialiased">
<header class="sticky top-0 z-30 border-b border-theme surface">
  <div class="mx-auto flex h-14 max-w-5xl items-center gap-3 px-4">
    <span class="grid size-8 place-items-center rounded-lg bg-primary-600 text-sm font-semibold text-white"><?= e(initials($company['name'])) ?></span>
    <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold"><?= e($company['name']) ?></p><p class="truncate text-xs text-muted"><?= e($contact['customer_name']) ?></p></div>
    <span class="hidden text-sm text-muted sm:inline"><?= e($contact['name']) ?></span>
    <form method="post" action="<?= e(url('/portal/logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= icon('log-out') ?><span class="hidden sm:inline">Sign out</span></button></form>
  </div>
  <nav class="mx-auto flex max-w-5xl gap-1 overflow-x-auto px-3" aria-label="Portal">
    <?php foreach ($tabs as $href => [$ic, $label]): ?>
      <a href="<?= e(url($href)) ?>" class="tab shrink-0 <?= $active($href) ? 'active' : '' ?>"><?= icon($ic, 'size-4') ?><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main class="mx-auto max-w-5xl px-4 py-6">
  <?php foreach ($flash ?? [] as $f): ?><div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $f['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300' ?>" role="alert"><?= e($f['message']) ?></div><?php endforeach; ?>
  <?= $content ?>
</main>
</body></html>

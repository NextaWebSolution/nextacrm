<?php defined('NX_ROOT') || exit; ?><!doctype html>
<html lang="en" class="h-full" data-theme="system"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?></title><meta name="description" content="<?= e($pageDesc) ?>">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml"><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"><script src="<?= e(asset('js/theme.js')) ?>"></script>
</head><body class="min-h-full bg-[var(--bg)] antialiased" style="--brand: <?= e($company['brand_color'] ?? '#4f46e5') ?>">
<header class="border-b border-theme surface"><div class="mx-auto flex h-14 max-w-4xl items-center gap-3 px-4"><a href="<?= e(url('/help')) ?>" class="flex items-center gap-2 font-semibold"><span class="grid size-8 place-items-center rounded-lg bg-primary-600 text-white"><?= icon('book-open', 'size-4') ?></span><?= e(($company['name'] ?? '') . ' Help Centre') ?></a><span class="flex-1"></span><a href="<?= e(url('/portal')) ?>" class="btn btn-ghost btn-sm"><?= icon('door-open') ?>Client portal</a></div></header>

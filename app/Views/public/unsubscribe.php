<?php defined('NX_ROOT') || exit; ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"><title>Unsubscribe</title><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"></head>
<body class="grid min-h-screen place-items-center bg-zinc-100 px-4 text-zinc-900"><main class="w-full max-w-md rounded-xl border border-zinc-200 bg-white p-6 text-center">
<?php if ($done): ?><p class="text-lg font-semibold">You're unsubscribed</p><p class="mt-2 text-sm text-zinc-500"><?= e($email) ?> won't receive these e-mails any more.</p>
<?php else: ?><p class="text-lg font-semibold">Unsubscribe?</p><p class="mt-2 text-sm text-zinc-500">Stop follow-up e-mails to <?= e($email) ?>.</p>
<form method="post" action="<?= e(url('/unsubscribe/' . $token)) ?>" class="mt-5"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Unsubscribe</button></form><?php endif; ?>
</main></body></html>

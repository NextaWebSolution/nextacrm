<?php defined('NX_ROOT') || exit; ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= e($form['name']) ?></title><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"></head>
<body class="bg-transparent p-3" style="--brand:#4f46e5">
<div class="mx-auto max-w-lg rounded-xl border border-zinc-200 bg-white p-5 text-zinc-900 sm:p-6">
<?php if ($sent): ?>
  <div class="py-6 text-center"><span class="mx-auto grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-600"><?= icon('check', 'size-6') ?></span><p class="mt-4 font-semibold">Thank you!</p><p class="mt-1 text-sm text-zinc-600"><?= e($form['success_message'] ?: 'We received your message and will get back to you shortly.') ?></p></div>
<?php else: ?>
  <form method="post" action="<?= e(url('/forms/' . $form['token'])) ?>" class="space-y-3.5">
    <input type="hidden" name="_ts" value="<?= (int) $ts ?>"><input type="hidden" name="_sig" value="<?= e($sig) ?>">
    <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px"><label>Leave empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
    <?php foreach ($fields as $f): $n = $f['name']; ?>
      <div><label class="label text-zinc-600" for="ff_<?= e($n) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?></label>
      <?php if ($n === 'description'): ?><textarea id="ff_<?= e($n) ?>" name="<?= e($n) ?>" rows="4" class="textarea bg-white text-zinc-900" <?= !empty($f['required']) ? 'required' : '' ?>></textarea>
      <?php else: ?><input id="ff_<?= e($n) ?>" name="<?= e($n) ?>" type="<?= $n === 'email' ? 'email' : ($n === 'phone' || $n === 'whatsapp' ? 'tel' : ($n === 'estimated_value' ? 'number' : 'text')) ?>" class="input bg-white text-zinc-900" <?= !empty($f['required']) ? 'required' : '' ?> maxlength="190"><?php endif; ?></div>
    <?php endforeach; ?>
    <?php foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $u): if (!empty($_GET[$u])): ?><input type="hidden" name="<?= $u ?>" value="<?= e(substr((string) $_GET[$u], 0, 100)) ?>"><?php endif; endforeach; ?>
    <button type="submit" class="btn btn-primary w-full">Submit</button>
  </form>
<?php endif; ?>
</div></body></html>

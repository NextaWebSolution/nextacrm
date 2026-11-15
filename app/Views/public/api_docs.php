<?php defined('NX_ROOT') || exit; ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($spec['info']['title']) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"><script src="<?= e(asset('js/theme.js')) ?>"></script></head>
<body class="bg-app" style="--brand:#4f46e5">
<div class="mx-auto max-w-5xl px-4 py-10">
  <h1 class="text-2xl font-semibold tracking-tight"><?= e($spec['info']['title']) ?> <span class="badge badge-indigo before:hidden align-middle">v<?= e($spec['info']['version']) ?></span></h1>
  <p class="mt-2 whitespace-pre-line text-sm text-muted"><?= e($spec['info']['description']) ?></p>
  <p class="mt-3 text-sm">Base URL: <code class="rounded bg-surface-2 px-1.5 py-0.5"><?= e($spec['servers'][0]['url']) ?></code> · <a class="link" href="<?= e(url('/api/v1/openapi.json')) ?>">openapi.json</a> (import into Postman, Insomnia or Swagger UI)</p>
  <pre class="mt-4 overflow-x-auto rounded-lg border border-theme surface p-4 text-xs">curl -H "Authorization: Bearer nx_…" "<?= e($spec['servers'][0]['url']) ?>/leads?filter[status]=New&amp;sort=-created_at"
curl -X POST -H "Authorization: Bearer nx_…" -H "Content-Type: application/json" \
     -d '{"name":"Jane Doe","email":"jane@example.com","source":"Website"}' "<?= e($spec['servers'][0]['url']) ?>/leads"</pre>
  <?php $tags = []; foreach ($spec['paths'] as $p => $ops) { foreach ($ops as $m => $op) { if (is_array($op) && isset($op['tags'])) { $tags[$op['tags'][0]][] = [$m, $p, $op]; } } } ?>
  <?php foreach ($tags as $tag => $ops): ?>
    <section class="card mt-6"><div class="card-header"><h2 class="card-title"><?= e($tag) ?></h2></div>
      <ul class="divide-theme">
        <?php foreach ($ops as [$m, $p, $op]): ?>
          <li class="flex items-center gap-3 px-4 py-2 text-[13px]"><span class="w-16 rounded px-1.5 py-0.5 text-center text-[11px] font-bold uppercase <?= ['get' => 'bg-sky-100 text-sky-800', 'post' => 'bg-emerald-100 text-emerald-800', 'put' => 'bg-amber-100 text-amber-800', 'delete' => 'bg-red-100 text-red-800'][$m] ?? '' ?>"><?= e($m) ?></span><code><?= e($p) ?></code><span class="ml-auto text-muted"><?= e($op['summary']) ?></span></li>
        <?php endforeach; ?>
      </ul></section>
  <?php endforeach; ?>
</div></body></html>

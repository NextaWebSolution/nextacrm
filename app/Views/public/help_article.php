<?php defined('NX_ROOT') || exit; $pageTitle = $a['title'] . ' · ' . ($company['name'] ?? '') . ' Help Centre'; $pageDesc = str_limit(preg_replace('/[#*_`\[\]()>-]+/', '', (string) $a['body']), 155); require __DIR__ . '/_help_head.php'; ?>
<main class="mx-auto max-w-3xl px-4 py-8">
  <nav class="text-sm text-muted"><a class="link" href="<?= e(url('/help')) ?>">Help Centre</a> / <?= e($a['category'] ?: 'General') ?></nav>
  <article class="kb-article mt-3">
    <h1 class="text-2xl font-semibold tracking-tight"><?= e($a['title']) ?></h1>
    <p class="mt-1 text-xs text-faint">Updated <?= e(fmt_date(substr((string) $a['updated_at'], 0, 10))) ?></p>
    <div class="mt-6"><?= markdown_safe($a['body']) ?></div>
  </article>
  <?php if ($related): ?><section class="card mt-8"><div class="card-header"><h2 class="card-title">Related articles</h2></div><ul class="divide-theme"><?php foreach ($related as $x): ?><li><a class="block px-4 py-2.5 text-sm hover:bg-[var(--surface-2)]" href="<?= e(url('/help/' . $x['id'] . '-' . $x['slug'])) ?>"><?= e($x['title']) ?></a></li><?php endforeach; ?></ul></section><?php endif; ?>
  <p class="mt-8 text-sm text-muted">Still need help? <a class="link" href="<?= e(url('/portal/tickets')) ?>">Open a support ticket</a>.</p>
</main></body></html>

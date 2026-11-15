<?php defined('NX_ROOT') || exit; $pageTitle = 'Help Centre · ' . ($company['name'] ?? ''); $pageDesc = 'Answers and guides from ' . ($company['name'] ?? '') . '.'; require __DIR__ . '/_help_head.php'; ?>
<main class="mx-auto max-w-4xl px-4 py-8">
  <h1 class="text-2xl font-semibold tracking-tight">How can we help?</h1>
  <form method="get" action="<?= e(url('/help')) ?>" class="mt-4 flex gap-2"><input name="q" value="<?= e($q) ?>" class="input h-11" placeholder="Search articles…" aria-label="Search articles"><button class="btn btn-primary h-11" type="submit"><?= icon('search') ?>Search</button></form>
  <?php if ($q !== ''): ?><p class="mt-3 text-sm text-muted"><?= (int) $count ?> result<?= $count === 1 ? '' : 's' ?> for “<?= e($q) ?>” · <a class="link" href="<?= e(url('/help')) ?>">clear</a></p><?php endif; ?>
  <div class="mt-6 grid gap-5 sm:grid-cols-2">
    <?php foreach ($groups as $cat => $items): ?>
      <section class="card"><div class="card-header"><h2 class="card-title"><?= e($cat) ?></h2><span class="text-xs text-faint"><?= count($items) ?></span></div>
        <ul class="divide-theme"><?php foreach ($items as $a): ?><li><a class="block px-4 py-2.5 text-sm hover:bg-[var(--surface-2)]" href="<?= e(url('/help/' . $a['id'] . '-' . $a['slug'])) ?>"><?= e($a['title']) ?></a></li><?php endforeach; ?></ul>
      </section>
    <?php endforeach; ?>
    <?php if (!$groups): ?><p class="card p-8 text-center text-sm text-muted sm:col-span-2">No articles found.</p><?php endif; ?>
  </div>
</main></body></html>

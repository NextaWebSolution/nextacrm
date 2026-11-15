<?php defined('NX_ROOT') || exit; ?>
<h1 class="text-xl font-semibold tracking-tight">Hello, <?= e($contact['first_name'] ?: $contact['name']) ?></h1>
<p class="mt-1 text-sm text-muted">Here's what's happening with <?= e($contact['customer_name']) ?>.</p>
<div class="mt-5 grid gap-3 sm:grid-cols-3">
  <?php $dueTotal = array_sum(array_map(fn ($i) => (float) $i['balance'], $due)); $cur = $due ? reset($due)['currency'] : ($company['currency'] ?? 'INR'); ?>
  <a href="<?= e(url('/portal/invoices')) ?>" class="card p-4 hover:border-primary-300"><p class="text-xs text-muted">Amount due</p><p class="mt-1 text-xl font-semibold"><?= e(money($dueTotal, $cur)) ?></p><p class="mt-1 text-xs text-faint"><?= count($due) ?> open invoice<?= count($due) === 1 ? '' : 's' ?></p></a>
  <a href="<?= e(url('/portal/quotes')) ?>" class="card p-4 hover:border-primary-300"><p class="text-xs text-muted">Quotations awaiting you</p><p class="mt-1 text-xl font-semibold"><?= count($quotes) ?></p></a>
  <a href="<?= e(url('/portal/tickets')) ?>" class="card p-4 hover:border-primary-300"><p class="text-xs text-muted">Open support tickets</p><p class="mt-1 text-xl font-semibold"><?= count($tickets) ?></p></a>
</div>
<?php if ($due): ?>
<section class="card mt-5"><div class="card-header"><h2 class="card-title">Invoices to pay</h2></div>
  <ul class="divide-theme"><?php foreach ($due as $i): ?>
    <li class="flex items-center gap-3 px-4 py-3"><span class="min-w-0 flex-1"><a class="font-medium link" href="<?= e(url('/portal/invoices/' . $i['id'])) ?>"><?= e($i['number']) ?></a><span class="block text-xs text-muted">Due <?= e(fmt_date($i['due_date'])) ?></span></span><?= badge($i['status']) ?><span class="w-32 text-right font-medium"><?= e(money((float) $i['balance'], $i['currency'])) ?></span></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if ($projects): ?>
<section class="card mt-5"><div class="card-header"><h2 class="card-title">Projects</h2><a href="<?= e(url('/portal/projects')) ?>" class="text-xs link">View all</a></div>
  <ul class="divide-theme"><?php foreach (array_slice($projects, 0, 5) as $p): ?>
    <li class="flex items-center gap-3 px-4 py-3"><a class="min-w-0 flex-1 truncate font-medium link" href="<?= e(url('/portal/projects/' . $p['id'])) ?>"><?= e($p['name']) ?></a><?= badge($p['status']) ?><span class="progress w-28"><span style="width:<?= (int) $p['progress'] ?>%"></span></span><span class="w-10 text-right text-xs text-muted"><?= (int) $p['progress'] ?>%</span></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>

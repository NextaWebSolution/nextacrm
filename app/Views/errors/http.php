<?php defined('NX_ROOT') || exit; ?>
<div class="mx-auto flex max-w-md flex-col items-center py-20 text-center">
  <span class="grid size-14 place-items-center rounded-2xl bg-[color-mix(in_oklab,var(--text)_6%,transparent)] text-muted"><?= icon($status === 404 ? 'search' : ($status === 403 ? 'lock' : 'triangle-exclamation-point'), 'size-6') ?></span>
  <p class="mt-6 text-sm font-semibold text-primary-600"><?= (int) $status ?></p>
  <h1 class="mt-1 text-2xl font-semibold tracking-tight"><?= e($status === 404 ? 'Not found' : ($status === 403 ? 'Access denied' : 'Something went wrong')) ?></h1>
  <p class="mt-2 text-sm text-muted"><?= e($message) ?></p>
  <div class="mt-6 flex gap-2"><a href="<?= e(url('/')) ?>" class="btn btn-primary">Go to dashboard</a><a href="<?= e(url("/")) ?>" class="btn btn-secondary" x-data @click.prevent="history.back()">Go back</a></div>
</div>

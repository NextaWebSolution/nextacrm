<?php defined('NX_ROOT') || exit; ?>
<h1 class="text-2xl font-semibold tracking-tight">Reset your password</h1>
<p class="mt-1.5 text-sm text-muted">Enter your e-mail and we'll send you a link to choose a new password.</p>
<form method="post" action="<?= e(url('/forgot-password')) ?>" class="mt-8 space-y-4">
  <?= csrf_field() ?>
  <div><label class="label" for="email">E-mail</label><input id="email" name="email" type="email" class="input" required autofocus autocomplete="email"></div>
  <button type="submit" class="btn btn-primary h-10 w-full">Send reset link</button>
</form>
<a href="<?= e(url('/login')) ?>" class="mt-6 inline-flex items-center gap-1.5 text-sm link"><?= icon('arrow-left', 'size-4') ?>Back to sign in</a>

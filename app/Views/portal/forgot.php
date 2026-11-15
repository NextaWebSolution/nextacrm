<?php defined('NX_ROOT') || exit; ?>
<h1 class="text-2xl font-semibold tracking-tight">Reset password</h1>
<p class="mt-1.5 text-sm text-muted">We'll e-mail you a link to choose a new password.</p>
<form method="post" action="<?= e(url('/portal/forgot')) ?>" class="mt-6 space-y-4">
  <?= csrf_field() ?>
  <div><label class="label" for="email">E-mail</label><input id="email" name="email" type="email" class="input" required autofocus></div>
  <button type="submit" class="btn btn-primary h-10 w-full">Send reset link</button>
  <p class="text-center text-sm"><a href="<?= e(url('/portal/login')) ?>" class="link">Back to sign in</a></p>
</form>

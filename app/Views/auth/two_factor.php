<?php defined('NX_ROOT') || exit; ?>
<span class="grid size-11 place-items-center rounded-xl bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300"><?= icon('shield-check', 'size-5') ?></span>
<h1 class="mt-5 text-2xl font-semibold tracking-tight">Two-factor authentication</h1>
<p class="mt-1.5 text-sm text-muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
<form method="post" action="<?= e(url('/login/2fa')) ?>" class="mt-8 space-y-4" x-data="{ busy: false }" @submit="busy = true">
  <?= csrf_field() ?>
  <div><label class="label" for="code">Authentication code</label><input id="code" name="code" class="input text-center font-mono text-lg tracking-[.35em]" inputmode="numeric" autocomplete="one-time-code" maxlength="11" required autofocus placeholder="000000"></div>
  <button type="submit" class="btn btn-primary h-10 w-full" :disabled="busy">Verify</button>
</form>
<a href="<?= e(url('/login')) ?>" class="mt-6 inline-flex items-center gap-1.5 text-sm link"><?= icon('arrow-left', 'size-4') ?>Back to sign in</a>

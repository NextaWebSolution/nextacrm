<?php defined('NX_ROOT') || exit; ?>
<h1 class="text-2xl font-semibold tracking-tight">Welcome back</h1>
<p class="mt-1.5 text-sm text-muted">Sign in to your workspace.</p>
<form method="post" action="<?= e(url('/login')) ?>" class="mt-8 space-y-4" x-data="{ busy: false, show: false }" @submit="busy = true">
  <?= csrf_field() ?>
  <div><label class="label" for="email">E-mail</label><input id="email" name="email" type="email" class="input" value="<?= e(old('email')) ?>" required autocomplete="username" autofocus></div>
  <div>
    <div class="flex items-center justify-between"><label class="label" for="password">Password</label><a href="<?= e(url('/forgot-password')) ?>" class="mb-1 text-xs link">Forgot password?</a></div>
    <div class="relative"><input id="password" name="password" :type="show ? 'text' : 'password'" class="input pr-10" required autocomplete="current-password">
      <button type="button" class="absolute inset-y-0 right-0 grid w-10 place-items-center text-faint hover:text-[var(--text)]" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'"><span x-show="!show"><?= icon('eye') ?></span><span x-show="show" x-cloak><?= icon('eye-off') ?></span></button></div>
  </div>
  <button type="submit" class="btn btn-primary h-10 w-full" :disabled="busy"><span x-show="busy" x-cloak><?= icon('loader-circle', 'size-4 animate-spin') ?></span>Sign in</button>
</form>
<p class="mt-8 flex items-center gap-1.5 text-xs text-faint"><?= icon('lock', 'size-3.5') ?>Protected with rate limiting and optional two-factor authentication.</p>

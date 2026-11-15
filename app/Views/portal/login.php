<?php defined('NX_ROOT') || exit; ?>
<h1 class="text-2xl font-semibold tracking-tight">Sign in</h1>
<p class="mt-1.5 text-sm text-muted">View invoices, pay online and get support.</p>
<form method="post" action="<?= e(url('/portal/login')) ?>" class="mt-6 space-y-4" x-data="{ busy: false }" @submit="busy = true">
  <?= csrf_field() ?>
  <div><label class="label" for="email">E-mail</label><input id="email" name="email" type="email" class="input" required autocomplete="username" autofocus></div>
  <div><div class="flex items-center justify-between"><label class="label" for="password">Password</label><a href="<?= e(url('/portal/forgot')) ?>" class="mb-1 text-xs link">Forgot password?</a></div><input id="password" name="password" type="password" class="input" required autocomplete="current-password"></div>
  <button type="submit" class="btn btn-primary h-10 w-full" :disabled="busy">Sign in</button>
</form>

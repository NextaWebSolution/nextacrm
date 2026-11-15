<?php defined('NX_ROOT') || exit; ?>
<h1 class="text-2xl font-semibold tracking-tight"><?= $row['purpose'] === 'invite' ? 'Activate your account' : 'Choose a new password' ?></h1>
<p class="mt-1.5 text-sm text-muted"><?= e($row['email']) ?></p>
<form method="post" action="<?= e(url('/portal/activate/' . $token)) ?>" class="mt-6 space-y-4">
  <?= csrf_field() ?>
  <div><label class="label" for="password">New password</label><input id="password" name="password" type="password" class="input" required minlength="<?= (int) setting('security.password_min', 10) ?>" autocomplete="new-password"></div>
  <div><label class="label" for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" class="input" required autocomplete="new-password"></div>
  <p class="text-xs text-faint">At least <?= (int) setting('security.password_min', 10) ?> characters with letters and numbers.</p>
  <button type="submit" class="btn btn-primary h-10 w-full">Save password</button>
</form>

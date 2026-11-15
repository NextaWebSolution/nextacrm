<?php defined('NX_ROOT') || exit; $invite = $row['purpose'] === 'invite'; ?>
<h1 class="text-2xl font-semibold tracking-tight"><?= $invite ? 'Activate your account' : 'Choose a new password' ?></h1>
<p class="mt-1.5 text-sm text-muted"><?= $invite ? 'Hi ' . e($row['name']) . ', set a password to finish setting up your account.' : 'For ' . e($row['email']) ?></p>
<form method="post" action="<?= e(url('/reset-password/' . $token)) ?>" class="mt-8 space-y-4" x-data="{ pw: '' }">
  <?= csrf_field() ?>
  <div><label class="label" for="password">New password</label><input id="password" name="password" type="password" class="input" required minlength="<?= (int) setting('security.password_min', 10) ?>" autocomplete="new-password" x-model="pw" autofocus>
    <div class="mt-2 flex gap-1"><template x-for="i in 4"><span class="h-1 flex-1 rounded-full" :class="(pw.length >= 8) + (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) + /\d/.test(pw) + (/[^A-Za-z0-9]/.test(pw) || pw.length >= 14) >= i ? 'bg-emerald-500' : 'bg-[var(--border)]'"></span></template></div>
    <p class="help">At least <?= (int) setting('security.password_min', 10) ?> characters with letters and numbers.</p></div>
  <div><label class="label" for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" class="input" required autocomplete="new-password"></div>
  <button type="submit" class="btn btn-primary h-10 w-full"><?= $invite ? 'Activate account' : 'Update password' ?></button>
</form>

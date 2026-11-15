<?php defined('NX_ROOT') || exit; $prefs = json_decode((string) $u['preferences'], true) ?: []; ?>
<div class="mx-auto max-w-3xl space-y-5">
  <div class="tabs"><a class="tab active" href="<?= e(url('/profile')) ?>">Profile</a><a class="tab" href="<?= e(url('/profile/security')) ?>">Security</a></div>
  <form method="post" action="<?= e(url('/profile')) ?>" class="card">
    <?= csrf_field() ?>
    <div class="card-header"><h2 class="card-title">Personal details</h2></div>
    <div class="grid gap-4 p-5 sm:grid-cols-2">
      <div><label class="label" for="name">Full name</label><input id="name" name="name" class="input" value="<?= e($u['name']) ?>" required></div>
      <div><label class="label">E-mail</label><input class="input" value="<?= e($u['email']) ?>" disabled><p class="help">Ask an administrator to change your e-mail.</p></div>
      <div><label class="label" for="job_title">Job title</label><input id="job_title" name="job_title" class="input" value="<?= e($u['job_title']) ?>"></div>
      <div><label class="label" for="phone">Phone</label><input id="phone" name="phone" class="input" value="<?= e($u['phone']) ?>"></div>
      <div><label class="label" for="timezone">Time zone</label><select id="timezone" name="timezone" class="select"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option <?= $tz === $u['timezone'] ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select></div>
      <div class="flex items-end"><label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" name="email_notifications" value="1" <?= ($prefs['email_notifications'] ?? true) ? 'checked' : '' ?>>E-mail me about assignments and reminders</label></div>
    </div>
    <div class="flex justify-end border-t border-theme px-5 py-3"><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
  <form method="post" action="<?= e(url('/profile/password')) ?>" class="card">
    <?= csrf_field() ?>
    <div class="card-header"><h2 class="card-title">Change password</h2></div>
    <div class="grid gap-4 p-5 sm:grid-cols-3">
      <div><label class="label" for="cp">Current password</label><input id="cp" type="password" name="current_password" class="input" required autocomplete="current-password"></div>
      <div><label class="label" for="np">New password</label><input id="np" type="password" name="password" class="input" required autocomplete="new-password" minlength="<?= (int) setting('security.password_min', 10) ?>"></div>
      <div><label class="label" for="np2">Confirm</label><input id="np2" type="password" name="password_confirmation" class="input" required autocomplete="new-password"></div>
    </div>
    <div class="flex items-center justify-between border-t border-theme px-5 py-3"><p class="text-xs text-faint">Changing your password signs out your other sessions.</p><button class="btn btn-primary" type="submit">Update password</button></div>
  </form>
</div>

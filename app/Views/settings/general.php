<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<form method="post" action="<?= e(url('/settings/general')) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">General</h2></div>
  <div class="grid gap-4 p-5 sm:grid-cols-2">
    <div><label class="label" for="app_name">Application name</label><input id="app_name" name="app_name" class="input" value="<?= e(setting('app.name')) ?>" maxlength="60"><p class="help">Shown in the sidebar, e-mails and browser tabs.</p></div>
    <div><label class="label" for="per_page">Rows per page</label><input id="per_page" name="per_page" type="number" min="10" max="100" class="input" value="<?= (int) setting('app.per_page', 25) ?>"></div>
    <div><label class="label" for="date_format">Date format</label><select id="date_format" name="date_format" class="select"><?php foreach (['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'M j, Y'] as $f): ?><option value="<?= e($f) ?>" <?= setting('app.date_format') === $f ? 'selected' : '' ?>><?= e(date($f)) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="time_format">Time format</label><select id="time_format" name="time_format" class="select"><option value="H:i" <?= setting('app.time_format') === 'H:i' ? 'selected' : '' ?>>24-hour (<?= date('H:i') ?>)</option><option value="h:i A" <?= setting('app.time_format') === 'h:i A' ? 'selected' : '' ?>>12-hour (<?= date('h:i A') ?>)</option></select></div>
    <div><label class="label" for="week_start">Week starts on</label><select id="week_start" name="week_start" class="select"><option value="1" <?= (int) setting('app.week_start', 1) === 1 ? 'selected' : '' ?>>Monday</option><option value="0" <?= (int) setting('app.week_start', 1) === 0 ? 'selected' : '' ?>>Sunday</option></select></div>
  </div>
  <div class="flex justify-end border-t border-theme px-5 py-3"><button class="btn btn-primary" type="submit">Save</button></div>
</form>
<section class="card p-5 text-sm">
  <h2 class="card-title">Quick links</h2>
  <div class="mt-3 grid gap-2 sm:grid-cols-2">
    <a class="link" href="<?= e(url('/settings/users')) ?>">Invite your team →</a>
    <a class="link" href="<?= e(url('/settings/email')) ?>">Configure outgoing e-mail →</a>
    <a class="link" href="<?= e(url('/settings/storage')) ?>">Choose file storage (S3, Wasabi, FTP…) →</a>
    <a class="link" href="<?= e(url('/settings/system')) ?>">Set up the cron job →</a>
  </div>
</section>
</div></div>

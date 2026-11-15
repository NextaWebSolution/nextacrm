<?php defined('NX_ROOT') || exit; App\Core\View::share('extraScripts', ['js/qrcode.min.js']); ?>
<div class="mx-auto max-w-3xl space-y-5">
  <div class="tabs"><a class="tab" href="<?= e(url('/profile')) ?>">Profile</a><a class="tab active" href="<?= e(url('/profile/security')) ?>">Security</a></div>
  <section class="card">
    <div class="card-header"><h2 class="card-title">Two-factor authentication</h2><?= $u['two_factor_enabled'] ? badge('Enabled', 'green') : badge('Off', 'slate') ?></div>
    <div class="p-5">
      <?php if ($recovery): ?>
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900/50 dark:bg-amber-950/30">
          <p class="font-medium">Save these recovery codes now. Each works once if you lose your phone. They will not be shown again.</p>
          <pre id="rc" class="mt-3 grid grid-cols-2 gap-1 font-mono text-[13px] sm:grid-cols-5"><?php foreach ($recovery as $c): ?><span><?= e($c) ?></span><?php endforeach; ?></pre>
          <button type="button" class="btn btn-secondary btn-sm mt-3" data-copy="<?= e(implode("\n", $recovery)) ?>"><?= icon('copy') ?>Copy codes</button>
        </div>
      <?php endif; ?>
      <?php if ($u['two_factor_enabled']): ?>
        <p class="text-sm text-muted">Your account is protected with an authenticator app.</p>
        <form method="post" action="<?= e(url('/profile/2fa/disable')) ?>" class="mt-4 flex flex-wrap items-end gap-2" data-confirm="Turn off two-factor authentication?" data-danger><?= csrf_field() ?><div><label class="label" for="pw">Confirm with your password</label><input id="pw" type="password" name="password" class="input w-64" required></div><button class="btn btn-danger-ghost" type="submit">Turn off 2FA</button></form>
      <?php elseif ($pendingSecret): ?>
        <ol class="list-decimal space-y-2 pl-5 text-sm">
          <li>Open Google Authenticator, Microsoft Authenticator, Authy or 1Password.</li>
          <li>Scan this QR code with the app:<div class="mt-2 w-44 rounded-lg border border-theme bg-white p-2" x-data="qr(<?= ej(['text' => $otpUri]) ?>)"></div></li>
          <li>Or add the account manually with this setup key (or open the link on your phone):<div class="mt-2 flex flex-wrap items-center gap-2"><code class="rounded-lg border border-theme bg-surface-2 px-3 py-2 font-mono text-[13px] tracking-wider"><?= e(trim(chunk_split($pendingSecret, 4, ' '))) ?></code><button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e($pendingSecret) ?>"><?= icon('copy') ?>Copy</button><a class="btn btn-ghost btn-sm" href="<?= e($otpUri) ?>"><?= icon('smartphone') ?>Open in app</a></div></li>
          <li>Enter the 6-digit code the app shows:</li>
        </ol>
        <form method="post" action="<?= e(url('/profile/2fa/enable')) ?>" class="mt-3 flex gap-2"><?= csrf_field() ?><input name="code" class="input w-40 text-center font-mono tracking-[.3em]" inputmode="numeric" maxlength="6" required autocomplete="one-time-code" placeholder="000000"><button class="btn btn-primary" type="submit">Verify &amp; enable</button></form>
      <?php else: ?>
        <p class="text-sm text-muted">Add a second step at sign-in using an authenticator app. Strongly recommended for administrators.</p>
        <form method="post" action="<?= e(url('/profile/2fa/setup')) ?>" class="mt-4"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= icon('shield-check') ?>Set up two-factor authentication</button></form>
      <?php endif; ?>
    </div>
  </section>
  <section class="card">
    <div class="card-header"><h2 class="card-title">Active sessions</h2><form method="post" action="<?= e(url('/profile/sessions/revoke')) ?>" data-confirm="Sign out all other sessions?"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Sign out other sessions</button></form></div>
    <ul class="divide-theme">
      <?php foreach ($sessions as $s): $cur = (int) $s['id'] === (int) $currentSession; ?>
        <li class="flex items-center gap-3 px-4 py-3">
          <span class="text-faint"><?= icon(preg_match('/Mobile|Android|iPhone/i', (string) $s['user_agent']) ? 'smartphone' : 'monitor', 'size-5') ?></span>
          <div class="min-w-0 flex-1"><p class="truncate text-[13px] font-medium"><?= e(str_limit((string) $s['user_agent'], 90)) ?></p><p class="text-xs text-muted"><?= e($s['ip']) ?> · last active <?= e(time_ago($s['last_seen_at'])) ?></p></div>
          <?php if ($cur): ?><?= badge('This device', 'green') ?><?php else: ?><form method="post" action="<?= e(url('/profile/sessions/revoke')) ?>"><?= csrf_field() ?><input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Sign out</button></form><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>

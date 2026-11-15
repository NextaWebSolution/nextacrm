<?php
defined('NX_ROOT') || exit;
use App\Services\CompanyService;
use App\Services\EntityRegistry;
use App\Core\Session;

$u = user();
$company = CompanyService::current();
$theme = $u['prefs']['theme'] ?? 'system';
$appName = (string) setting('app.name', 'NextaCRM');
$path = App\Core\App::request()->path;
$nav = EntityRegistry::nav();
$companies = CompanyService::available();
$flash = Session::takeFlash();
$collapsed = !empty($u['prefs']['sidebar_collapsed']);
$quickCreate = [];
foreach (['leads', 'deals', 'customers', 'contacts', 'tasks', 'tickets', 'projects', 'events', 'expenses'] as $qe) {
    $d = EntityRegistry::all()[$qe] ?? null;
    if ($d && can($d['module'], 'create') && module_enabled($d['module'])) {
        $quickCreate[] = ['entity' => $qe, 'label' => $d['singular'], 'icon' => $d['icon']];
    }
}
$isActive = fn (string $p) => $p === '/' ? $path === '/' : str_starts_with($path, $p);
?><!doctype html>
<html lang="en" class="h-full <?= $theme === 'dark' ? 'dark' : '' ?>" data-theme="<?= e($theme) ?>" style="--brand: <?= e($company['brand_color'] ?? '#4f46e5') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(url('/')) ?>">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0c0c0e" media="(prefers-color-scheme: dark)">
<title><?= e(($title ?? 'Dashboard') . ' · ' . $appName) ?></title>
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<script defer src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach (($extraScripts ?? []) as $xs): ?><script defer src="<?= e(asset($xs)) ?>"></script><?php endforeach; ?>
<script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="h-full antialiased text-[13px] sm:text-sm" x-data="shell" data-flash="<?= ej($flash) ?>" data-collapsed="<?= $collapsed ? '1' : '0' ?>" @keydown.window="hotkey($event)">

<!-- Mobile drawer overlay -->
<div x-cloak x-show="mobileNav" x-transition.opacity class="overlay lg:hidden" @click="mobileNav = false"></div>

<div class="flex h-full">
  <!-- Sidebar -->
  <aside class="fixed inset-y-0 left-0 z-[65] flex flex-col border-r border-theme surface transition-[width,transform] duration-200 lg:z-30"
         :class="[mobileNav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0', collapsed ? 'lg:w-[4.25rem]' : 'lg:w-60', 'w-72']">
    <div class="flex h-14 items-center gap-2.5 px-3.5 border-b border-theme shrink-0">
      <a href="<?= e(url('/')) ?>" class="flex items-center gap-2.5 min-w-0">
        <span class="grid size-8 place-items-center rounded-lg bg-primary-600 text-white shadow-sm shrink-0"><?= icon('layers', 'size-4.5') ?></span>
        <span class="font-semibold tracking-tight truncate" x-show="!collapsed || mobileNav"><?= e($appName) ?></span>
      </a>
      <button type="button" class="btn btn-ghost btn-icon btn-sm ml-auto lg:hidden" @click="mobileNav = false" aria-label="Close menu"><?= icon('x') ?></button>
    </div>

    <?php if (count($companies) > 1): ?>
    <div class="px-3 pt-3" x-show="!collapsed || mobileNav">
      <form method="post" action="<?= e(url('/switch-company')) ?>">
        <?= csrf_field() ?>
        <label class="sr-only" for="company-switch">Company</label>
        <select id="company-switch" name="company_id" class="select input-sm" x-data @change="$el.form.requestSubmit()">
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $company['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php endif; ?>

    <nav class="flex-1 overflow-y-auto px-2.5 py-3" aria-label="Main">
      <a href="<?= e(url('/')) ?>" class="nav-item <?= $isActive('/') ? 'active' : '' ?>" title="Dashboard"><?= icon('layout-dashboard') ?><span x-show="!collapsed || mobileNav">Dashboard</span></a>
      <?php if (can('calendar', 'view')): ?>
      <a href="<?= e(url('/calendar')) ?>" class="nav-item <?= $isActive('/calendar') ? 'active' : '' ?>" title="Calendar"><?= icon('calendar') ?><span x-show="!collapsed || mobileNav">Calendar</span></a>
      <?php endif; ?>
      <a href="<?= e(url('/notifications')) ?>" class="nav-item <?= $isActive('/notifications') ? 'active' : '' ?>" title="Inbox"><?= icon('inbox') ?><span x-show="!collapsed || mobileNav" class="flex-1">Inbox</span><span x-show="unread > 0 && (!collapsed || mobileNav)" x-text="unread" class="badge badge-indigo before:hidden"></span></a>

      <?php foreach ($nav as $group => $items): ?>
        <div class="nav-group" x-show="!collapsed || mobileNav"><?= e($group) ?></div>
        <div class="my-2 h-px bg-[var(--border)]" x-show="collapsed && !mobileNav"></div>
        <?php foreach ($items as $it): ?>
          <a href="<?= e($it['url']) ?>" class="nav-item <?= $isActive('/r/' . $it['key']) ? 'active' : '' ?>" title="<?= e($it['label']) ?>"><?= icon($it['icon']) ?><span x-show="!collapsed || mobileNav" class="truncate"><?= e($it['label']) ?></span></a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <div class="nav-group" x-show="!collapsed || mobileNav">Workspace</div>
      <div class="my-2 h-px bg-[var(--border)]" x-show="collapsed && !mobileNav"></div>
      <?php if (can('files', 'view')): ?>
      <a href="<?= e(url('/files')) ?>" class="nav-item <?= $isActive('/files') ? 'active' : '' ?>" title="Files"><?= icon('folder') ?><span x-show="!collapsed || mobileNav">Files</span></a>
      <?php endif; ?>
      <?php if (can('reports', 'view')): ?>
      <a href="<?= e(url('/reports')) ?>" class="nav-item <?= $isActive('/reports') ? 'active' : '' ?>" title="Reports"><?= icon('chart-column') ?><span x-show="!collapsed || mobileNav">Reports</span></a>
      <?php endif; ?>
      <?php if (!empty($u['is_admin'])): ?>
      <a href="<?= e(url('/settings')) ?>" class="nav-item <?= $isActive('/settings') ? 'active' : '' ?>" title="Settings"><?= icon('settings') ?><span x-show="!collapsed || mobileNav">Settings</span></a>
      <?php endif; ?>
    </nav>

    <div class="border-t border-theme p-2.5 shrink-0 hidden lg:block">
      <button type="button" class="nav-item w-full" @click="toggleSidebar()" :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'">
        <span :class="collapsed && 'rotate-180'" class="transition-transform"><?= icon('chevron-left') ?></span><span x-show="!collapsed">Collapse</span>
      </button>
    </div>
  </aside>

  <!-- Main column -->
  <div class="flex min-w-0 flex-1 flex-col transition-[padding] duration-200" :class="collapsed ? 'lg:pl-[4.25rem]' : 'lg:pl-60'">
    <header class="sticky top-0 z-40 flex h-14 items-center gap-2 border-b border-theme bg-[color-mix(in_oklab,var(--surface)_88%,transparent)] px-3 backdrop-blur sm:px-5 no-print">
      <button type="button" class="btn btn-ghost btn-icon lg:hidden" @click="mobileNav = true" aria-label="Open menu"><?= icon('menu', 'size-5') ?></button>

      <nav class="hidden min-w-0 items-center gap-1.5 text-[13px] text-muted md:flex" aria-label="Breadcrumb">
        <?php foreach (($breadcrumbs ?? []) as $label => $href): ?>
          <a href="<?= e($href) ?>" class="hover:text-[var(--text)] truncate"><?= e($label) ?></a><span class="text-faint"><?= icon('chevron-right', 'size-3.5') ?></span>
        <?php endforeach; ?>
        <span class="font-medium text-[var(--text)] truncate max-w-[28rem]"><?= e($title ?? '') ?></span>
      </nav>
      <span class="font-semibold truncate md:hidden"><?= e($title ?? '') ?></span>

      <div class="ml-auto flex items-center gap-1 sm:gap-1.5">
        <button type="button" @click="$dispatch('open-palette')" class="hidden h-9 w-56 items-center gap-2 rounded-lg border border-theme bg-surface-2 px-3 text-left text-[13px] text-faint hover:border-[var(--border-strong)] md:flex">
          <?= icon('search') ?><span class="flex-1">Search…</span><span class="kbd">Ctrl K</span>
        </button>
        <button type="button" @click="$dispatch('open-palette')" class="btn btn-ghost btn-icon md:hidden" aria-label="Search"><?= icon('search', 'size-5') ?></button>

        <!-- Running timer -->
        <template x-if="timer">
          <div class="flex items-center gap-1.5 rounded-lg border border-emerald-300/60 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-800 dark:border-emerald-700/40 dark:bg-emerald-950/40 dark:text-emerald-300">
            <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span></span>
            <a :href="timer.task_id ? '<?= e(url('/r/tasks/')) ?>' + timer.task_id : '#'" class="hidden max-w-32 truncate sm:inline" x-text="timer.label"></a>
            <span class="tabular-nums" x-text="timerText"></span>
            <button type="button" class="ml-0.5 rounded p-0.5 hover:bg-emerald-200/60 dark:hover:bg-emerald-800/40" @click="stopTimer()" title="Stop timer"><?= icon('square', 'size-3.5') ?></button>
          </div>
        </template>

        <?php if ($quickCreate): ?>
        <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
          <button type="button" class="btn btn-primary btn-sm" @click="open = !open" aria-haspopup="menu" :aria-expanded="open"><?= icon('plus') ?><span class="hidden sm:inline">New</span></button>
          <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false" class="menu right-0 mt-2 w-56" role="menu">
            <?php foreach ($quickCreate as $qc): ?>
              <button type="button" class="menu-item" role="menuitem" @click="open = false; $dispatch('open-form', { entity: '<?= e($qc['entity']) ?>', quick: true })"><?= icon($qc['icon'], 'size-4 text-faint') ?><?= e($qc['label']) ?></button>
            <?php endforeach; ?>
            <?php if (can('invoices', 'create') && module_enabled('invoices')): ?><div class="menu-sep"></div><a class="menu-item" href="<?= e(url('/documents/invoice/new')) ?>"><?= icon('receipt', 'size-4 text-faint') ?>Invoice</a><?php endif; ?>
            <?php if (can('quotes', 'create') && module_enabled('quotes')): ?><a class="menu-item" href="<?= e(url('/documents/quote/new')) ?>"><?= icon('file-text', 'size-4 text-faint') ?>Quotation</a><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Notifications -->
        <div class="relative" x-data="notifications" @keydown.escape="open = false">
          <button type="button" class="btn btn-ghost btn-icon relative" @click="toggle()" aria-label="Notifications">
            <?= icon('bell', 'size-5') ?>
            <span x-cloak x-show="$store.nx.unread > 0" class="absolute right-1.5 top-1.5 grid min-w-4 h-4 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white" x-text="$store.nx.unread > 9 ? '9+' : $store.nx.unread"></span>
          </button>
          <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false" class="menu right-0 mt-2 w-[min(24rem,calc(100vw-1.5rem))] p-0">
            <div class="flex items-center justify-between border-b border-theme px-3.5 py-2.5">
              <span class="text-sm font-semibold">Notifications</span>
              <button type="button" class="btn btn-ghost btn-xs" @click="markAll()" x-show="$store.nx.unread > 0">Mark all read</button>
            </div>
            <div class="max-h-96 overflow-y-auto">
              <template x-if="loading"><div class="space-y-2 p-3"><div class="skeleton h-10"></div><div class="skeleton h-10"></div></div></template>
              <template x-if="!loading && items.length === 0"><div class="px-4 py-10 text-center text-sm text-muted">You're all caught up.</div></template>
              <template x-for="n in items" :key="n.id">
                <a :href="n.url || '#'" @click="read(n)" class="flex gap-3 border-b border-theme px-3.5 py-2.5 last:border-0 hover:bg-[color-mix(in_oklab,var(--text)_4%,transparent)]">
                  <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="n.read ? 'bg-transparent' : 'bg-primary-500'"></span>
                  <span class="min-w-0 flex-1"><span class="block text-[13px] font-medium" x-text="n.title"></span><span class="block truncate text-xs text-muted" x-text="n.body"></span><span class="mt-0.5 block text-[11px] text-faint" x-text="n.ago"></span></span>
                </a>
              </template>
            </div>
            <a href="<?= e(url('/notifications')) ?>" class="block border-t border-theme px-3.5 py-2 text-center text-xs font-medium link">View all</a>
          </div>
        </div>

        <!-- User menu -->
        <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
          <button type="button" class="flex items-center gap-2 rounded-lg p-1 hover:bg-[color-mix(in_oklab,var(--text)_5%,transparent)]" @click="open = !open" aria-label="Account menu">
            <?= App\Services\FieldRenderer::avatar($u['name'], hue_for($u['email']), 'size-7 text-[11px]') ?>
          </button>
          <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false" class="menu right-0 mt-2 w-64">
            <div class="px-2.5 py-2"><div class="truncate text-sm font-semibold"><?= e($u['name']) ?></div><div class="truncate text-xs text-muted"><?= e($u['email']) ?> · <?= e($u['role_name'] ?? '') ?></div></div>
            <div class="menu-sep"></div>
            <a href="<?= e(url('/profile')) ?>" class="menu-item"><?= icon('user', 'size-4 text-faint') ?>My profile</a>
            <a href="<?= e(url('/profile/security')) ?>" class="menu-item"><?= icon('shield-check', 'size-4 text-faint') ?>Security &amp; 2FA</a>
            <div class="menu-sep"></div>
            <div class="px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-faint">Theme</div>
            <div class="seg mx-1.5 mb-1.5 flex">
              <button type="button" class="flex-1 justify-center" :class="theme === 'light' && 'on'" @click="setTheme('light')"><?= icon('sun', 'size-3.5') ?>Light</button>
              <button type="button" class="flex-1 justify-center" :class="theme === 'dark' && 'on'" @click="setTheme('dark')"><?= icon('moon', 'size-3.5') ?>Dark</button>
              <button type="button" class="flex-1 justify-center" :class="theme === 'system' && 'on'" @click="setTheme('system')"><?= icon('monitor', 'size-3.5') ?>Auto</button>
            </div>
            <div class="menu-sep"></div>
            <button type="button" class="menu-item" @click="open = false; $dispatch('open-shortcuts')"><?= icon('circle-question-mark', 'size-4 text-faint') ?>Keyboard shortcuts</button>
            <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit" class="menu-item text-red-600 dark:text-red-400"><?= icon('log-out', 'size-4') ?>Sign out</button></form>
          </div>
        </div>
      </div>
    </header>

    <main id="main" class="flex-1 px-3 pb-24 pt-4 sm:px-5 sm:pt-5 lg:pb-10">
      <?= $content ?>
    </main>
  </div>
</div>

<!-- Mobile bottom navigation -->
<nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-theme surface pb-[env(safe-area-inset-bottom)] lg:hidden no-print" aria-label="Quick navigation">
  <?php
  $bottom = [['/', 'house', 'Home']];
  if (can('leads', 'view') && module_enabled('leads')) { $bottom[] = ['/r/leads', 'target', 'Leads']; } elseif (can('tickets', 'view')) { $bottom[] = ['/r/tickets', 'life-buoy', 'Tickets']; }
  if (can('tasks', 'view') && module_enabled('tasks')) { $bottom[] = ['/r/tasks', 'square-check-big', 'Tasks']; } elseif (can('deals', 'view')) { $bottom[] = ['/r/deals', 'handshake', 'Deals']; }
  ?>
  <?php foreach ($bottom as [$href, $ic, $lb]): ?>
    <a href="<?= e(url($href)) ?>" class="flex flex-col items-center justify-center gap-0.5 py-2 text-[10.5px] font-medium <?= $isActive($href) ? 'text-primary-600 dark:text-primary-400' : 'text-muted' ?>"><?= icon($ic, 'size-5') ?><?= e($lb) ?></a>
  <?php endforeach; ?>
  <button type="button" @click="$dispatch('open-palette')" class="flex flex-col items-center justify-center gap-0.5 py-2 text-[10.5px] font-medium text-muted"><?= icon('search', 'size-5') ?>Search</button>
  <button type="button" @click="mobileNav = true" class="flex flex-col items-center justify-center gap-0.5 py-2 text-[10.5px] font-medium text-muted"><?= icon('menu', 'size-5') ?>More</button>
</nav>

<?= App\Core\View::partial('partials/palette', ['quickCreate' => $quickCreate, 'nav' => $nav]) ?>
<?= App\Core\View::partial('partials/overlays') ?>
</body>
</html>

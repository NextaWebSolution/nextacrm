<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<section class="card">
  <div class="card-header flex-wrap"><h2 class="card-title">Audit log</h2>
    <form method="get" class="flex flex-wrap gap-2"><input name="action" class="input input-sm w-44" placeholder="Action contains…" value="<?= e(App\Core\App::request()->query['action'] ?? '') ?>"><select name="user" class="select input-sm w-44" data-autosubmit><option value="">All users</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) (App\Core\App::request()->query['user'] ?? 0) === $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select><button class="btn btn-secondary btn-sm" type="submit">Filter</button></form></div>
  <div class="overflow-x-auto"><table class="data-table">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th class="hidden lg:table-cell">Details</th><th>IP</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $a): ?><tr>
        <td class="whitespace-nowrap text-muted"><?= e(fmt_datetime($a['created_at'])) ?></td><td><?= e($a['user_name'] ?? '—') ?></td><td><code class="text-xs"><?= e($a['action']) ?></code></td>
        <td><?= $a['entity'] && $a['record_id'] && App\Services\EntityRegistry::exists($a['entity']) ? '<a class="link" href="' . e(url('/r/' . $a['entity'] . '/' . $a['record_id'])) . '">' . e($a['entity'] . ' #' . $a['record_id']) . '</a>' : e(trim(($a['entity'] ?? '') . ' ' . ($a['record_id'] ?? ''))) ?></td>
        <td class="hidden max-w-sm truncate text-xs text-muted lg:table-cell" title="<?= e((string) $a['changes']) ?>"><?= e(str_limit((string) $a['changes'], 120)) ?></td><td class="text-xs text-muted"><?= e($a['ip']) ?></td></tr><?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="flex items-center justify-between border-t border-theme px-4 py-2.5 text-xs text-muted"><span><?= number_format($total) ?> entries</span><span class="flex gap-1"><?php if ($page > 1): ?><a class="btn btn-secondary btn-xs" href="?<?= e(http_build_query(['page' => $page - 1] + App\Core\App::request()->query)) ?>">Previous</a><?php endif; ?><?php if ($page < $pages): ?><a class="btn btn-secondary btn-xs" href="?<?= e(http_build_query(['page' => $page + 1] + App\Core\App::request()->query)) ?>">Next</a><?php endif; ?></span></div>
</section>
</div></div>

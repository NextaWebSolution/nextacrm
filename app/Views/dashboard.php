<?php defined('NX_ROOT') || exit; App\Core\View::share('extraScripts', ['js/chart.min.js']);
use App\Services\FieldRenderer;
$u = user();
$hour = (int) (new DateTimeImmutable('now', user_tz()))->format('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$tasksDef = App\Services\EntityRegistry::get('tasks');
?>
<div class="mx-auto max-w-[1400px]">
  <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold tracking-tight sm:text-2xl"><?= e($greet . ', ' . explode(' ', $u['name'])[0]) ?></h1>
      <p class="mt-0.5 text-sm text-muted"><?= e((new DateTimeImmutable('now', user_tz()))->format('l, j F Y')) ?> · <?= e(App\Services\CompanyService::current()['name'] ?? '') ?></p>
    </div>
  </div>

  <?php if ($kpis): ?>
  <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
    <?php foreach ($kpis as $k): ?>
      <a href="<?= e($k['url']) ?>" class="card group p-4 transition-colors hover:border-[var(--border-strong)]">
        <div class="flex items-center justify-between text-muted"><span class="text-xs font-medium"><?= e($k['label']) ?></span><span class="<?= !empty($k['alert']) ? 'text-red-500' : 'text-faint' ?>"><?= icon($k['icon'], 'size-4') ?></span></div>
        <div class="mt-2 truncate text-lg font-semibold tracking-tight tabular-nums sm:text-xl"><?= e($k['value']) ?></div>
        <div class="mt-1 flex items-center gap-1.5 text-xs">
          <?php if (!empty($k['delta'])): ?><span class="inline-flex items-center gap-0.5 font-medium <?= $k['delta']['up'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' ?>"><?= icon($k['delta']['up'] ? 'trending-up' : 'trending-down', 'size-3.5') ?><?= e($k['delta']['value']) ?></span><span class="text-faint">vs last month</span><?php endif; ?>
          <?php if (!empty($k['sub'])): ?><span class="truncate <?= !empty($k['alert']) ? 'text-red-600 dark:text-red-400' : 'text-faint' ?>"><?= e($k['sub']) ?></span><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="mt-4 grid gap-4 xl:grid-cols-3 *:min-w-0">
    <?php if ($revenue): ?>
    <section class="card xl:col-span-2">
      <div class="card-header"><h2 class="card-title">Revenue — invoiced vs collected</h2><span class="text-xs text-faint">Last 12 months</span></div>
      <div class="h-72 p-3" x-data="chart(<?= ej(['type' => 'bar', 'money' => true, 'labels' => $revenue['labels'], 'datasets' => [['label' => 'Invoiced', 'data' => $revenue['invoiced'], 'muted' => true], ['label' => 'Collected', 'data' => $revenue['collected'], 'color' => '#10b981']]]) ?>)"><canvas x-ref="canvas" aria-label="Revenue chart" role="img"></canvas></div>
    </section>
    <?php endif; ?>
    <?php if ($pipeline && array_sum($pipeline['counts'])): ?>
    <section class="card">
      <div class="card-header"><h2 class="card-title">Pipeline by stage</h2><a href="<?= e(url('/r/deals')) ?>" class="text-xs link">Open board</a></div>
      <div class="h-72 p-3" x-data="chart(<?= ej(['type' => 'bar', 'money' => true, 'labels' => $pipeline['labels'], 'datasets' => [['label' => 'Value', 'data' => $pipeline['amounts']]]]) ?>)"><canvas x-ref="canvas" aria-label="Pipeline chart" role="img"></canvas></div>
    </section>
    <?php elseif ($pipeline): ?>
    <section class="card grid place-items-center p-8 text-center"><div><p class="text-sm font-medium">No open deals yet</p><p class="mt-1 text-xs text-muted">Your pipeline chart appears once deals are added.</p><button type="button" class="btn btn-secondary btn-sm mt-3" @click="$dispatch('open-form', {entity: 'deals'})"><?= icon('plus') ?>New deal</button></div></section>
    <?php endif; ?>
  </div>

  <div class="mt-4 grid gap-4 lg:grid-cols-2 xl:grid-cols-3 *:min-w-0">
    <?php if (can('tasks', 'view')): ?>
    <section class="card">
      <div class="card-header"><h2 class="card-title">My open tasks</h2><a href="<?= e(url('/r/tasks', ['scope' => 'mine'])) ?>" class="text-xs link">View all</a></div>
      <?php if (!$myTasks): ?>
        <div class="px-4 py-10 text-center text-sm text-muted"><?= icon('circle-check', 'mx-auto mb-2 size-6 text-emerald-500') ?>Nothing assigned to you. Nice.</div>
      <?php else: ?>
      <ul class="divide-theme">
        <?php foreach ($myTasks as $t): ?>
          <li class="flex items-center gap-3 px-4 py-2.5 hover:bg-[color-mix(in_oklab,var(--text)_2.5%,transparent)]" data-href="<?= e(url('/r/tasks/' . $t['id'])) ?>" role="link">
            <span class="min-w-0 flex-1"><span class="block truncate text-[13px] font-medium"><?= e($t['title']) ?></span><span class="block truncate text-xs text-muted"><?= e($t['_labels']['project_id'] ?? 'No project') ?></span></span>
            <?= badge($t['priority']) ?>
            <span class="w-20 shrink-0 text-right text-xs"><?= $t['due_date'] ? FieldRenderer::html($tasksDef['fields']['due_date'], $t) : '<span class="text-faint">No date</span>' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($leadSources && array_sum($leadSources['counts'] ?? [])): ?>
    <section class="card">
      <div class="card-header"><h2 class="card-title">Leads by source</h2><span class="text-xs text-faint">Last 90 days</span></div>
      <div class="h-64 p-3" x-data="chart(<?= ej(['type' => 'doughnut', 'labels' => $leadSources['labels'], 'datasets' => [['label' => 'Leads', 'data' => $leadSources['counts']]]]) ?>)"><canvas x-ref="canvas" aria-label="Lead sources" role="img"></canvas></div>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-header"><h2 class="card-title">Upcoming</h2><?php if (can('calendar', 'view')): ?><a href="<?= e(url('/calendar')) ?>" class="text-xs link">Calendar</a><?php endif; ?></div>
      <?php if (!$events): ?>
        <div class="px-4 py-10 text-center text-sm text-muted">No upcoming meetings.</div>
      <?php else: ?>
      <ul class="divide-theme">
        <?php foreach ($events as $ev): $d = new DateTimeImmutable($ev['starts_at'], new DateTimeZone('UTC')); $d = $d->setTimezone(user_tz()); ?>
          <li class="flex items-center gap-3 px-4 py-2.5" data-href="<?= e(url('/r/events/' . $ev['id'])) ?>" role="link">
            <span class="flex w-11 shrink-0 flex-col items-center rounded-lg border border-theme py-1"><span class="text-[10px] font-semibold uppercase text-primary-600"><?= e($d->format('M')) ?></span><span class="text-base font-semibold leading-none"><?= e($d->format('j')) ?></span></span>
            <span class="min-w-0 flex-1"><span class="block truncate text-[13px] font-medium"><?= e($ev['title']) ?></span><span class="block text-xs text-muted"><?= e($d->format((string) setting('app.time_format', 'H:i'))) ?> · <?= e($ev['type']) ?></span></span>
            <?php if ($ev['meeting_url']): ?><a href="<?= e($ev['meeting_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-xs">Join</a><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

    <section class="card lg:col-span-2 xl:col-span-<?= $leadSources && array_sum($leadSources['counts'] ?? []) ? '3' : '1' ?>">
      <div class="card-header"><h2 class="card-title">Recent activity</h2></div>
      <?php if (!$activity): ?>
        <div class="px-4 py-10 text-center text-sm text-muted">Activity across your workspace will show up here.</div>
      <?php else: ?>
      <ul class="divide-theme">
        <?php foreach ($activity as $a): ?>
          <li class="flex gap-3 px-4 py-2.5">
            <?= $a['user'] ? FieldRenderer::avatar($a['user']['name'], $a['user']['hue'], 'size-7 text-[10px] mt-0.5') : '<span class="avatar size-7" style="--h:220">·</span>' ?>
            <div class="min-w-0 flex-1 text-[13px]">
              <p class="truncate"><span class="font-medium"><?= e($a['user']['name'] ?? 'System') ?></span> <span class="text-muted"><?= e($a['type'] === 'system' ? 'updated' : 'logged a ' . strtolower(App\Services\ActivityService::TYPES[$a['type']] ?? 'note')) ?> on <?= e(strtolower($a['entity_label'])) ?></span> <a class="link font-medium" href="<?= e(url('/r/' . $a['entity'] . '/' . $a['record_id'])) ?>"><?= e($a['title']) ?></a></p>
              <p class="truncate text-xs text-muted"><?= e(str_replace("\n", ' · ', (string) $a['body'])) ?></p>
            </div>
            <span class="shrink-0 text-xs text-faint"><?= e(time_ago($a['created_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
  </div>
</div>

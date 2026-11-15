<?php defined('NX_ROOT') || exit;
$items = [
    'Workspace' => ['general' => ['/settings', 'General', 'settings-2'], 'company' => ['/settings/company', 'Company & branding', 'building'], 'customization' => ['/settings/customization', 'Lists, pipelines & taxes', 'sliders-horizontal'], 'fields' => ['/settings/fields', 'Custom fields', 'columns-3']],
    'People' => ['users' => ['/settings/users', 'Users', 'users'], 'roles' => ['/settings/roles', 'Roles & permissions', 'key-round'], 'teams' => ['/settings/teams', 'Teams', 'network']],
    'Automation' => ['workflows' => ['/settings/workflows', 'Workflows', 'workflow'], 'forms' => ['/settings/forms', 'Web-to-lead forms', 'globe'], 'api' => ['/settings/api', 'API & webhooks', 'webhook']],
    'System' => ['email' => ['/settings/email', 'E-mail delivery', 'mail'], 'storage' => ['/settings/storage', 'File storage', 'hard-drive'], 'security' => ['/settings/security', 'Security', 'shield'], 'audit' => ['/settings/audit', 'Audit log', 'activity'], 'system' => ['/settings/system', 'System & updates', 'server']],
];
foreach (App\Core\Extensions::settingsTabs() as $g => $links) {
    $items[$g] = array_merge($items[$g] ?? [], $links);
}
?>
<nav class="lg:sticky lg:top-20 lg:self-start" aria-label="Settings">
  <div class="-mx-3 flex gap-1 overflow-x-auto px-3 pb-2 lg:mx-0 lg:block lg:space-y-0.5 lg:overflow-visible lg:px-0">
    <?php foreach ($items as $group => $links): ?>
      <div class="nav-group hidden lg:block"><?= e($group) ?></div>
      <?php foreach ($links as $k => [$href, $label, $ic]): ?>
        <a href="<?= e(url($href)) ?>" class="nav-item shrink-0 <?= ($tab ?? '') === $k ? 'active' : '' ?>"><?= icon($ic) ?><span class="whitespace-nowrap"><?= e($label) ?></span></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</nav>

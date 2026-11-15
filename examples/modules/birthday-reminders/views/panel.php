<?php defined('NX_ROOT') || exit;
if (empty($rec['date_of_birth'])) return;
$md = substr((string) $rec['date_of_birth'], 5, 5);
$next = strtotime(date('Y') . '-' . $md);
if ($next < strtotime('today')) $next = strtotime((date('Y') + 1) . '-' . $md);
$days = (int) round(($next - strtotime('today')) / 86400);
?>
<section class="card p-4 text-sm"><p class="font-medium">🎂 Next birthday</p><p class="mt-1 text-muted"><?= e(date('j F', $next)) ?> · <?= $days === 0 ? 'today!' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's') ?></p></section>

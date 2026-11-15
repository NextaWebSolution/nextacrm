<?php defined('NX_ROOT') || exit;
if (!can('sequences', 'view') || !App\Core\Permissions::can($entity, 'edit', $rec)) return;
$db = App\Core\App::db();
$enr = $db->all('SELECT e.*, s.name FROM sequence_enrollments e JOIN email_sequences s ON s.id = e.sequence_id WHERE e.entity = ? AND e.record_id = ? AND e.company_id = ? ORDER BY e.id DESC', [$entity, (int) $rec['id'], App\Core\Auth::companyId()]);
$opts = array_filter(App\Services\SequenceService::options(), fn ($n, $id) => $db->value('SELECT entity FROM email_sequences WHERE id = ?', [$id]) === $entity, ARRAY_FILTER_USE_BOTH);
if (!$enr && !$opts) return;
?>
<section class="card">
  <div class="card-header"><h2 class="card-title flex items-center gap-2"><?= icon('mails', 'size-4') ?>Sequences</h2></div>
  <div class="space-y-2 p-4 text-[13px]">
    <?php foreach ($enr as $en): ?><div class="flex items-center gap-2"><a class="min-w-0 flex-1 truncate link" href="<?= e(url('/sequences/' . $en['sequence_id'])) ?>"><?= e($en['name']) ?></a><?= badge(ucfirst($en['status']), $en['status'] === 'active' ? 'blue' : 'slate') ?></div><?php endforeach; ?>
    <?php if ($opts && can('sequences', 'create') && !empty($rec['email'])): ?>
      <form method="post" action="<?= e(url('/r/' . $entity . '/' . (int) $rec['id'] . '/sequence')) ?>" data-ajax class="flex gap-2 pt-1"><?= csrf_field() ?><select name="sequence_id" class="select input-sm" aria-label="Sequence"><?php foreach ($opts as $id => $n): ?><option value="<?= (int) $id ?>"><?= e($n) ?></option><?php endforeach; ?></select><button class="btn btn-secondary btn-sm" type="submit">Enrol</button></form>
    <?php endif; ?>
  </div>
</section>

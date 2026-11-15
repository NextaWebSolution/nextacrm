<?php defined('NX_ROOT') || exit;
use App\Services\ApprovalService;
$raw = App\Core\App::db()->find($entity, (int) $rec['id']);
$flow = ApprovalService::flowFor($entity, $raw);
$req = ApprovalService::current($entity, (int) $rec['id']);
if (!$flow && !$req) return;
$status = $raw['approval_status'] ?? null;
$canEdit = App\Core\Permissions::can($entity, 'edit', $rec);
$actions = $req ? App\Core\App::db()->all('SELECT a.*, u.name FROM approval_actions a LEFT JOIN users u ON u.id = a.user_id WHERE a.request_id = ? ORDER BY a.id', [$req['id']]) : [];
$reqFlow = $req ? App\Core\App::db()->find('approval_flows', (int) $req['flow_id']) : null;
?>
<section class="card" x-data="{ comment: '' }">
  <div class="card-header"><h2 class="card-title flex items-center gap-2"><?= icon('stamp', 'size-4') ?>Approval</h2>
    <?= $status === 'approved' ? badge('Approved', 'green') : ($status === 'pending' ? badge('Pending', 'amber') : ($status === 'rejected' ? badge('Rejected', 'red') : badge('Required', 'slate'))) ?></div>
  <div class="space-y-3 p-4 text-[13px]">
    <?php if ($status === 'pending' && $req && $reqFlow): ?>
      <p class="text-muted">Step <?= (int) $req['current_step'] + 1 ?> of <?= count(json_decode((string) $reqFlow['steps'], true) ?: []) ?>: waiting for <span class="font-medium text-[var(--text)]"><?= e(ApprovalService::stepLabel($reqFlow, (int) $req['current_step'])) ?></span>.</p>
      <?php if (ApprovalService::canDecide($req, $reqFlow)): ?>
        <textarea class="textarea text-[13px]" rows="2" x-model="comment" placeholder="Comment (required to reject)"></textarea>
        <div class="flex gap-2">
          <button type="button" class="btn btn-primary btn-sm flex-1" @click="NX.api(NX.url('/approvals/<?= (int) $req['id'] ?>/decide'), { method: 'POST', body: { decision: 'approve', comment: comment } }).then(NX.handle).catch(NX.fail)"><?= icon('check') ?>Approve</button>
          <button type="button" class="btn btn-danger-ghost btn-sm flex-1" @click="NX.api(NX.url('/approvals/<?= (int) $req['id'] ?>/decide'), { method: 'POST', body: { decision: 'reject', comment: comment } }).then(NX.handle).catch(NX.fail)"><?= icon('x') ?>Reject</button>
        </div>
      <?php endif; ?>
    <?php elseif ($flow && $status !== 'approved' && $status !== 'pending'): ?>
      <p class="text-muted"><?= $entity === 'quotes' ? 'Amounts of ' . e(money((float) $flow['min_amount'])) . ' or more need approval before the quotation is sent.' : 'This expense needs approval (' . e($flow['name']) . ').' ?></p>
      <?php if ($canEdit): ?><button type="button" class="btn btn-secondary btn-sm" data-action="<?= e(url('/r/' . $entity . '/' . (int) $rec['id'] . '/approval')) ?>"><?= icon('send') ?>Submit for approval</button><?php endif; ?>
    <?php endif; ?>
    <?php if ($actions): ?><ul class="space-y-1.5 border-t border-theme pt-3 text-xs"><?php foreach ($actions as $a): ?><li><span class="font-medium"><?= e($a['name'] ?? 'User') ?></span> <span class="<?= $a['decision'] === 'approved' ? 'text-emerald-600' : 'text-red-600' ?>"><?= e($a['decision']) ?></span> step <?= (int) $a['step'] + 1 ?> · <?= e(time_ago($a['created_at'])) ?><?php if ($a['comment']): ?><span class="block text-muted">“<?= e($a['comment']) ?>”</span><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
</section>

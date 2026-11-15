<?php defined('NX_ROOT') || exit;
$sig = App\Services\SignatureService::forRecord($entity, (int) $rec['id']);
$canEdit = App\Core\Permissions::can($entity, 'edit', $rec);
if ($entity === 'quotes' && !$sig) return;
?>
<section class="card">
  <div class="card-header"><h2 class="card-title">E-signature</h2><?= $sig ? badge('Signed', 'green') : badge('Not signed', 'slate') ?></div>
  <div class="space-y-3 p-4 text-[13px]">
    <?php if ($sig): ?>
      <img src="<?= e($sig['image']) ?>" alt="Signature of <?= e($sig['signer_name']) ?>" class="h-14 rounded border border-theme bg-white p-1">
      <p><span class="font-medium"><?= e($sig['signer_name']) ?></span><span class="text-muted"> · <?= e(fmt_datetime($sig['signed_at'])) ?> · <?= e((string) $sig['signer_ip']) ?></span></p>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('/r/' . $entity . '/' . (int) $rec['id'] . '/signature-certificate')) ?>"><?= icon('file-text') ?>Signature certificate</a>
    <?php elseif ($canEdit): ?>
      <p class="text-muted">Send the client a secure link to review and sign this contract online.</p>
      <button type="button" class="btn btn-secondary btn-sm" x-data @click="NX.api(NX.url('/r/contracts/<?= (int) $rec['id'] ?>/signing-link'), { method: 'POST', body: {} }).then(d => { navigator.clipboard && navigator.clipboard.writeText(d.link); NX.toast(d.message); }).catch(NX.fail)"><?= icon('link') ?>Copy signing link</button>
    <?php endif; ?>
  </div>
</section>

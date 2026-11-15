<?php defined('NX_ROOT') || exit; ?><!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:'DejaVu Sans',sans-serif;font-size:11px;color:#1f2937;margin:34px}h1{font-size:18px;margin:0 0 4px}.muted{color:#6b7280}
table{width:100%;border-collapse:collapse;margin-top:14px}td{padding:7px 8px;border-bottom:1px solid #e5e7eb;vertical-align:top}td.k{width:32%;color:#6b7280}
.ok{color:#047857;font-weight:bold}.bad{color:#b91c1c;font-weight:bold}.box{border:1px solid #e5e7eb;padding:10px;margin-top:14px}
</style></head><body>
<h1>Electronic signature certificate</h1>
<div class="muted"><?= e($company['name'] ?? '') ?> · generated <?= e(gmdate('Y-m-d H:i')) ?> UTC</div>
<table>
  <tr><td class="k">Document</td><td><?= e($entity === 'quotes' ? 'Quotation ' . $rec['number'] : 'Contract: ' . $rec['title']) ?></td></tr>
  <?php if ($entity === 'quotes'): ?><tr><td class="k">Amount</td><td><?= e(money((float) $rec['total'], $rec['currency'])) ?></td></tr><?php endif; ?>
  <tr><td class="k">Signed by</td><td><?= e($sig['signer_name']) ?><?= $sig['signer_email'] ? ' &lt;' . e($sig['signer_email']) . '&gt;' : '' ?></td></tr>
  <tr><td class="k">Signed at (UTC)</td><td><?= e($sig['signed_at']) ?></td></tr>
  <tr><td class="k">IP address</td><td><?= e((string) $sig['signer_ip']) ?></td></tr>
  <tr><td class="k">Browser</td><td><?= e((string) $sig['user_agent']) ?></td></tr>
  <tr><td class="k">Document fingerprint (SHA-256)</td><td style="font-family:'DejaVu Sans Mono',monospace;font-size:9px"><?= e($sig['document_hash']) ?></td></tr>
  <tr><td class="k">Integrity check</td><td class="<?= $intact ? 'ok' : 'bad' ?>"><?= $intact ? 'Document unchanged since signing' : 'Document has been modified after signing' ?></td></tr>
</table>
<div class="box"><div class="muted">Signature</div><img src="<?= e($sig['image']) ?>" style="height:80px;margin-top:6px" alt=""></div>
</body></html>

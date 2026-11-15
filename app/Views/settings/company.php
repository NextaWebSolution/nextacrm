<?php defined('NX_ROOT') || exit; App\Core\View::share('breadcrumbs', ['Settings' => url('/settings')]); ?>
<div class="mx-auto grid max-w-6xl grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
<?= App\Core\View::partial('partials/settings_nav', ['tab' => $tab]) ?>
<div class="min-w-0 space-y-5">
<form method="post" action="<?= e(url('/settings/company')) ?>" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <div class="card-header"><h2 class="card-title">Company profile</h2><span class="text-xs text-faint">Used on quotations, invoices and e-mails</span></div>
  <div class="grid gap-4 p-5 sm:grid-cols-2">
    <div><label class="label">Display name</label><input name="name" class="input" value="<?= e($company['name']) ?>" required></div>
    <div><label class="label">Legal name</label><input name="legal_name" class="input" value="<?= e($company['legal_name']) ?>"></div>
    <div><label class="label">E-mail</label><input name="email" type="email" class="input" value="<?= e($company['email']) ?>"></div>
    <div><label class="label">Phone</label><input name="phone" class="input" value="<?= e($company['phone']) ?>"></div>
    <div><label class="label">Website</label><input name="website" class="input" value="<?= e($company['website']) ?>"></div>
    <div><label class="label">Tax ID / GSTIN</label><input name="tax_id" class="input" value="<?= e($company['tax_id']) ?>"></div>
    <div class="sm:col-span-2"><label class="label">Address</label><textarea name="address" rows="2" class="textarea"><?= e($company['address']) ?></textarea></div>
    <div><label class="label">City</label><input name="city" class="input" value="<?= e($company['city']) ?>"></div>
    <div><label class="label">State</label><input name="state" class="input" value="<?= e($company['state']) ?>"></div>
    <div><label class="label">Country</label><input name="country" class="input" value="<?= e($company['country']) ?>"></div>
    <div><label class="label">Postal code</label><input name="postal_code" class="input" value="<?= e($company['postal_code']) ?>"></div>
    <div><label class="label">Base currency</label><select name="currency" class="select"><?php foreach ($currencies as $c): ?><option value="<?= e($c['code']) ?>" <?= $c['code'] === $company['currency'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="label">Time zone</label><select name="timezone" class="select"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option <?= $tz === $company['timezone'] ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="border-t border-theme p-5">
    <h3 class="text-sm font-semibold">Branding</h3>
    <div class="mt-3 grid gap-4 sm:grid-cols-2">
      <div><label class="label">Logo (PNG/JPG/WebP)</label>
        <?php if ($company['logo_file_id']): ?><div class="mb-2 flex items-center gap-3"><img src="<?= e(url('/files/' . $company['logo_file_id'] . '/view')) ?>" alt="Logo" class="max-h-12 rounded border border-theme bg-white p-1"><label class="flex items-center gap-1.5 text-xs text-muted"><input type="checkbox" class="checkbox" name="remove_logo" value="1">Remove</label></div><?php endif; ?>
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="input py-1.5"></div>
      <div><label class="label">Brand colour</label><div class="flex gap-2"><input type="color" name="brand_color" value="<?= e($company['brand_color']) ?>" class="h-9 w-14 cursor-pointer rounded-lg border border-theme bg-transparent p-1"><p class="help mt-2">Used for buttons, highlights and document accents.</p></div></div>
    </div>
  </div>
  <div class="border-t border-theme p-5">
    <h3 class="text-sm font-semibold">Numbering &amp; documents</h3>
    <div class="mt-3 grid gap-4 sm:grid-cols-3">
      <div><label class="label">Invoice prefix</label><input name="invoice_prefix" class="input" value="<?= e($company['invoice_prefix']) ?>"></div>
      <div><label class="label">Next invoice no.</label><input type="number" min="1" name="invoice_next" class="input" value="<?= (int) $company['invoice_next'] ?>"></div>
      <div><label class="label">Payment terms (days)</label><input type="number" min="0" name="payment_terms_days" class="input" value="<?= (int) $company['payment_terms_days'] ?>"></div>
      <div><label class="label">Quotation prefix</label><input name="quote_prefix" class="input" value="<?= e($company['quote_prefix']) ?>"></div>
      <div><label class="label">Next quotation no.</label><input type="number" min="1" name="quote_next" class="input" value="<?= (int) $company['quote_next'] ?>"></div>
      <div><label class="label">Ticket prefix</label><input name="ticket_prefix" class="input" value="<?= e($company['ticket_prefix']) ?>"></div>
      <div class="sm:col-span-3"><label class="label">Default terms</label><textarea name="invoice_terms" rows="3" class="textarea"><?= e($company['invoice_terms']) ?></textarea></div>
      <div class="sm:col-span-2"><label class="label">Bank / payment details (printed on invoices)</label><textarea name="bank_details" rows="3" class="textarea" placeholder="Bank, A/C no., IFSC, UPI ID…"><?= e($company['bank_details']) ?></textarea></div>
      <div><label class="label">Document footer</label><textarea name="invoice_footer" rows="3" class="textarea"><?= e($company['invoice_footer']) ?></textarea></div>
    </div>
  </div>
  <div class="flex justify-end border-t border-theme px-5 py-3"><button class="btn btn-primary" type="submit">Save company</button></div>
</form>
<section class="card">
  <div class="card-header"><h2 class="card-title">Business entities</h2><span class="text-xs text-faint">Each company keeps separate records, numbering, taxes and branding</span></div>
  <ul class="divide-theme"><?php foreach ($companies as $c): ?><li class="flex items-center gap-3 px-4 py-2.5 text-sm"><span class="size-3 rounded-full" style="background: <?= e($c['brand_color']) ?>"></span><span class="flex-1 font-medium"><?= e($c['name']) ?></span><span class="text-xs text-muted"><?= e($c['currency']) ?></span><?= (int) $c['id'] === (int) $company['id'] ? badge('Current', 'green') : '' ?></li><?php endforeach; ?></ul>
  <form method="post" action="<?= e(url('/settings/companies')) ?>" class="flex flex-wrap gap-2 border-t border-theme p-4"><?= csrf_field() ?><input name="name" class="input w-60" placeholder="New company name" required><select name="currency" class="select w-28"><?php foreach ($currencies as $c): ?><option><?= e($c['code']) ?></option><?php endforeach; ?></select><button class="btn btn-secondary" type="submit"><?= icon('plus') ?>Add company</button></form>
</section>
</div></div>

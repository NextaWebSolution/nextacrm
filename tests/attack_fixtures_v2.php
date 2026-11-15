<?php
// v2 attacker fixtures: two portal contacts on different customers, a payable invoice with a gateway attempt,
// Razorpay webhook secret, an approval flow, and an internal ticket note.
define('NX_ROOT', '/home/claude/testsite');
require NX_ROOT . '/app/bootstrap.php';
App\Core\App::loadConfig();
$db = App\Core\App::db();
$now = App\Core\DB::now();
$custs = $db->column('SELECT id FROM customers WHERE company_id = 1 AND deleted_at IS NULL ORDER BY id LIMIT 2');
$pw = password_hash('P0rtalPassw0rd!', PASSWORD_ARGON2ID);
$mkContact = function (int $cust, string $email) use ($db, $now, $pw) {
    return $db->insert('contacts', ['company_id' => 1, 'customer_id' => $cust, 'first_name' => 'Portal', 'last_name' => $email, 'name' => 'Portal ' . $email, 'email' => $email, 'portal_enabled' => 1, 'portal_password' => $pw, 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
};
$ca = $mkContact((int) $custs[0], 'portal-a@example.com');
$cb = $mkContact((int) $custs[1], 'portal-b@example.com');
$invB = (int) $db->value("SELECT id FROM invoices WHERE company_id = 1 AND customer_id = ? AND status <> 'Draft' AND deleted_at IS NULL ORDER BY id LIMIT 1", [$custs[1]]);
if (!$invB) {
    $tpl = $db->one('SELECT * FROM invoices WHERE company_id = 1 ORDER BY id LIMIT 1');
    unset($tpl['id']);
    $tpl['customer_id'] = $custs[1]; $tpl['number'] = 'INV-PORTAL-B'; $tpl['status'] = 'Sent'; $tpl['public_token'] = App\Core\Crypto::token(24);
    $invB = $db->insert('invoices', $tpl);
}
// A payable invoice for customer A with a pending Razorpay attempt.
$tpl = $db->one('SELECT * FROM invoices WHERE company_id = 1 ORDER BY id LIMIT 1');
unset($tpl['id']);
$tpl['customer_id'] = $custs[0]; $tpl['number'] = 'INV-PAY-TEST'; $tpl['status'] = 'Sent'; $tpl['total'] = 5000; $tpl['amount_paid'] = 0; $tpl['balance'] = 5000; $tpl['currency'] = 'INR'; $tpl['public_token'] = App\Core\Crypto::token(24);
$invA = $db->insert('invoices', $tpl);
$attempt = $db->insert('payment_attempts', ['company_id' => 1, 'invoice_id' => $invA, 'gateway' => 'razorpay', 'gateway_order_id' => 'plink_TEST123', 'amount' => 5000, 'currency' => 'INR', 'status' => 'created', 'source' => 'public', 'created_at' => $now, 'updated_at' => $now]);
App\Core\Settings::setMany(['pay.razorpay.enabled' => true, 'pay.razorpay.key_id' => 'rzp_test_x', 'pay.razorpay.key_secret' => 'ks_test', 'pay.razorpay.webhook_secret' => 'whsec_attack_test'], 1);
// internal note on a ticket of customer A
$tA = (int) $db->value('SELECT id FROM tickets WHERE company_id = 1 AND customer_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$custs[0]]);
if (!$tA) {
    $tA = $db->insert('tickets', ['company_id' => 1, 'customer_id' => $custs[0], 'number' => 'TKT-PA', 'subject' => 'Portal A ticket', 'status' => 'Open', 'priority' => 'Medium', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
}
$db->insert('ticket_replies', ['ticket_id' => $tA, 'user_id' => 1, 'body' => 'INTERNAL-SECRET-NOTE do not show', 'is_internal' => 1, 'created_at' => $now]);
$tB = (int) $db->value('SELECT id FROM tickets WHERE company_id = 1 AND customer_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$custs[1]]);
if (!$tB) {
    $tB = $db->insert('tickets', ['company_id' => 1, 'customer_id' => $custs[1], 'number' => 'TKT-PB', 'subject' => 'Portal B ticket', 'status' => 'Open', 'priority' => 'Medium', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
}
// Approval flow on expenses requiring the Administrator role; a sales user's expense awaiting approval.
$adminRole = (int) $db->value("SELECT id FROM roles WHERE name = 'Administrator'");
$db->query('DELETE FROM approval_flows WHERE company_id = 1');
$db->insert('approval_flows', ['company_id' => 1, 'entity' => 'expenses', 'name' => 'Expenses over 1000', 'min_amount' => 1000, 'steps' => json_encode([['type' => 'role', 'id' => $adminRole]]), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
$unsubToken = App\Core\Auth::actingAs(1, 1, fn () => App\Services\SequenceService::unsubscribeToken(1, 'lead@example.com'));
echo json_encode(['contact_a' => $ca, 'contact_b' => $cb, 'cust_a' => (int) $custs[0], 'cust_b' => (int) $custs[1], 'inv_b' => $invB, 'inv_a' => $invA, 'attempt' => $attempt, 'ticket_a' => $tA, 'ticket_b' => $tB, 'unsub' => $unsubToken]);

<?php
// Creates attacker fixtures in the test install: a rival company + admin, a low-privilege sales user, API tokens.
define('NX_ROOT', '/home/claude/testsite');
require NX_ROOT . '/app/bootstrap.php';
App\Core\App::loadConfig();
$db = App\Core\App::db();
$now = App\Core\DB::now();
$pw = App\Core\Crypto::hashPassword('Att@ckerPassw0rd!');
$role = fn ($n) => (int) $db->value('SELECT id FROM roles WHERE name = ?', [$n]);
$mk = function (string $email, string $name, int $roleId, array $companies) use ($db, $pw, $now) {
    $id = (int) $db->value('SELECT id FROM users WHERE email = ?', [$email]);
    if (!$id) {
        $id = $db->insert('users', ['name' => $name, 'email' => $email, 'password' => $pw, 'role_id' => $roleId, 'status' => 'active', 'timezone' => 'UTC', 'default_company_id' => $companies[0], 'preferences' => '{}', 'created_at' => $now, 'updated_at' => $now]);
    }
    foreach ($companies as $c) {
        if (!$db->value('SELECT id FROM company_user WHERE user_id = ? AND company_id = ?', [$id, $c])) {
            $db->insert('company_user', ['company_id' => $c, 'user_id' => $id]);
        }
    }
    return $id;
};
$c2 = (int) $db->value("SELECT id FROM companies WHERE name = 'Rival Corp'");
if (!$c2) {
    $c2 = $db->insert('companies', ['name' => 'Rival Corp', 'currency' => 'USD', 'timezone' => 'UTC', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
}
$rival = $mk('rival@example.com', 'Rival Admin', $role('Administrator') ?: 1, [$c2]);
$sales = $mk('sales@example.com', 'Sam Sales', $role('Sales'), [1]);
$secretLead = $db->insert('leads', ['company_id' => $c2, 'name' => 'Rival Secret Lead', 'email' => 'secret@rival.test', 'status' => 'New', 'created_by' => $rival, 'assigned_to' => $rival, 'created_at' => $now, 'updated_at' => $now]);
$adminLead = $db->insert('leads', ['company_id' => 1, 'name' => 'Admin Private Lead', 'email' => 'private@a.test', 'status' => 'New', 'created_by' => 1, 'assigned_to' => 1, 'created_at' => $now, 'updated_at' => $now]);
$tok = function (int $uid, int $cid, string $scope) use ($db, $now) {
    $plain = 'nx_' . bin2hex(random_bytes(20));
    $db->insert('api_tokens', ['company_id' => $cid, 'user_id' => $uid, 'name' => 'attack-' . $scope, 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 10), 'scope' => $scope, 'created_at' => $now]);
    return $plain;
};
$qs = $db->column('SELECT id FROM quotes WHERE company_id = 1 AND public_token IS NOT NULL ORDER BY id LIMIT 2');
if (count($qs) < 2) {
    $q = $db->find('quotes', (int) $qs[0]);
    unset($q['id']);
    $q['number'] = 'QT-ATTACK';
    $q['public_token'] = App\Core\Crypto::token(24);
    $qs[] = $db->insert('quotes', $q);
}
$db->query("UPDATE quotes SET status = 'Sent', valid_until = ? WHERE id = ?", [gmdate('Y-m-d', time() + 30 * 86400), $qs[0]]);
$db->query("UPDATE quotes SET status = 'Sent', valid_until = ? WHERE id = ?", [gmdate('Y-m-d', time() - 2 * 86400), $qs[1]]);
echo json_encode([
    'quote_valid' => $db->value('SELECT public_token FROM quotes WHERE id = ?', [$qs[0]]),
    'quote_expired' => $db->value('SELECT public_token FROM quotes WHERE id = ?', [$qs[1]]),
    'company2' => $c2, 'rival_id' => $rival, 'sales_id' => $sales, 'secret_lead' => $secretLead, 'admin_lead' => $adminLead,
    'rival_token' => $tok($rival, $c2, 'write'), 'admin_read_token' => $tok(1, 1, 'read'), 'sales_token' => $tok($sales, 1, 'write'),
    'employee_id' => (int) $db->value('SELECT id FROM employees WHERE company_id = 1 ORDER BY id LIMIT 1'),
    'invoice_id' => (int) $db->value("SELECT id FROM invoices WHERE company_id = 1 AND status IN ('Sent','Overdue') ORDER BY id LIMIT 1"),
    'quote_token' => $db->value("SELECT public_token FROM quotes WHERE company_id = 1 AND status = 'Sent' ORDER BY id LIMIT 1"),
    'admin_2fa_target' => 1,
]);

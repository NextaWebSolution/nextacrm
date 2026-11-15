<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\WebhookService;

final class ApiController extends Controller
{
    public function index(Request $r): Response
    {
        $db = App::db();
        return $this->view('settings/api', [
            'title' => 'API & webhooks', 'tab' => 'api',
            'tokens' => $db->all('SELECT t.*, u.name AS user_name FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id WHERE t.company_id = ? AND t.revoked_at IS NULL ORDER BY t.id DESC', [Auth::companyId()]),
            'webhooks' => $db->all('SELECT * FROM webhooks WHERE company_id = ? ORDER BY id DESC', [Auth::companyId()]),
            'deliveries' => $db->all('SELECT d.id, d.event, d.status, d.attempts, d.response_code, d.created_at, w.name FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE w.company_id = ? ORDER BY d.id DESC LIMIT 20', [Auth::companyId()]),
            'events' => WebhookService::events(),
            'newToken' => \App\Core\Session::pull('new_api_token'),
            'users' => \App\Services\LookupService::companyUsers(),
        ]);
    }

    public function createToken(Request $r): Response
    {
        $name = mb_substr(trim($r->str('name')), 0, 100) ?: 'API token';
        $scope = $r->str('scope') === 'write' ? 'write' : 'read';
        $uid = $r->int('user_id') ?: Auth::id();
        if (!in_array($uid, array_column(\App\Services\LookupService::companyUsers(), 'id'), true)) {
            throw new HttpException(422, 'Choose a user of this company.');
        }
        $days = $r->int('expires_days');
        $plain = 'nx_' . Crypto::token(30);
        App::db()->insert('api_tokens', ['company_id' => Auth::companyId(), 'user_id' => $uid, 'name' => $name, 'token_hash' => hash('sha256', $plain), 'token_prefix' => substr($plain, 0, 10), 'scope' => $scope, 'expires_at' => $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null, 'created_at' => DB::now()]);
        \App\Core\Session::put('new_api_token', $plain);
        Audit::log('api.token_created', null, null, ['name' => $name, 'scope' => $scope, 'user_id' => $uid]);
        return $this->redirect('/settings/api', 'success', 'Token created. Copy it now — it will not be shown again.');
    }

    public function revokeToken(Request $r, string $id): Response
    {
        App::db()->query('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND company_id = ?', [DB::now(), (int) $id, Auth::companyId()]);
        Audit::log('api.token_revoked', null, (int) $id);
        return $this->json(['ok' => true, 'message' => 'Token revoked', 'reload' => true]);
    }

    public function saveWebhook(Request $r): Response
    {
        $url = trim($r->str('url'));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            throw new HttpException(422, 'Enter a valid http(s) URL.');
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (filter_var($host, FILTER_VALIDATE_IP) && \App\Core\HttpClient::isBlockedIp($host)) {
            throw new HttpException(422, 'Private or reserved addresses are not allowed.');
        }
        $events = array_values(array_intersect((array) $r->input('events', []), array_merge(['*'], WebhookService::events())));
        if (!$events) {
            throw new HttpException(422, 'Select at least one event.');
        }
        $id = $r->int('id');
        $data = ['name' => mb_substr($r->str('name'), 0, 150) ?: parse_url($url, PHP_URL_HOST), 'url' => $url, 'events' => json_encode($events), 'is_active' => $r->input('is_active', '1') ? 1 : 0, 'updated_at' => DB::now()];
        $db = App::db();
        if ($id) {
            if (!$db->value('SELECT id FROM webhooks WHERE id = ? AND company_id = ?', [$id, Auth::companyId()])) {
                throw new HttpException(404);
            }
            $db->update('webhooks', $data + ['failure_count' => 0], ['id' => $id]);
            $msg = 'Webhook updated';
        } else {
            $secret = 'whsec_' . Crypto::token(24);
            $db->insert('webhooks', $data + ['company_id' => Auth::companyId(), 'secret' => Crypto::encrypt($secret), 'created_by' => Auth::id(), 'created_at' => DB::now()]);
            \App\Core\Session::put('new_webhook_secret', $secret);
            $msg = 'Webhook created. Signing secret: ' . $secret . ' (shown once)';
        }
        Audit::log('webhook.saved', null, $id ?: null, ['url' => $url]);
        return $this->json(['ok' => true, 'message' => $msg, 'reload' => true, 'sticky' => !$id]);
    }

    public function deleteWebhook(Request $r, string $id): Response
    {
        App::db()->query('DELETE FROM webhooks WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        return $this->json(['ok' => true, 'message' => 'Webhook deleted', 'reload' => true]);
    }

    public function testWebhook(Request $r, string $id): Response
    {
        $h = App::db()->one('SELECT * FROM webhooks WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        if (!$h) {
            throw new HttpException(404);
        }
        $payload = json_encode(['id' => bin2hex(random_bytes(8)), 'event' => 'ping', 'occurred_at' => gmdate('c'), 'data' => ['message' => 'Test delivery from NextaCRM']]);
        [$code, , $err] = WebhookService::deliver($h['url'], Crypto::decrypt($h['secret']), 'ping', (string) $payload);
        return $this->json(['ok' => $code >= 200 && $code < 300, 'message' => $err ? 'Failed: ' . $err : 'Endpoint responded with HTTP ' . $code]);
    }
}

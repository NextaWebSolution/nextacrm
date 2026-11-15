<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApprovalService;

final class ApprovalController extends Controller
{
    public function inbox(Request $r): Response
    {
        return $this->view('approvals/index', ['title' => 'Approvals', 'items' => ApprovalService::inbox()]);
    }

    public function submit(Request $r, string $entity, string $id): Response
    {
        if (!isset(ApprovalService::ENTITIES[$entity])) {
            throw new HttpException(404);
        }
        return $this->json(['ok' => true, 'message' => ApprovalService::submit($entity, (int) $id), 'reload' => true]);
    }

    public function decide(Request $r, string $id): Response
    {
        $msg = ApprovalService::decide((int) $id, $r->str('decision'), $r->str('comment'));
        return $this->json(['ok' => true, 'message' => $msg, 'reload' => true]);
    }

    // ── Settings: approval flows ──
    public function settings(Request $r): Response
    {
        $db = App::db();
        $flows = $db->all('SELECT * FROM approval_flows WHERE company_id = ? ORDER BY entity, min_amount', [Auth::companyId()]);
        return $this->view('settings/approvals', [
            'title' => 'Approval chains', 'tab' => 'approvals', 'flows' => $flows,
            'roles' => $db->all('SELECT id, name FROM roles ORDER BY name'),
            'users' => $db->all("SELECT u.id, u.name FROM users u JOIN company_user cu ON cu.user_id = u.id AND cu.company_id = ? WHERE u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.name", [Auth::companyId()]),
        ]);
    }

    public function saveFlow(Request $r): Response
    {
        $in = $r->json() ?: $r->all();
        $db = App::db();
        $entity = (string) ($in['entity'] ?? '');
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        if (!isset(ApprovalService::ENTITIES[$entity]) || $name === '') {
            throw new HttpException(422, 'Choose what the chain is for and give it a name.');
        }
        $steps = [];
        foreach (array_slice((array) ($in['steps'] ?? []), 0, 5) as $s) {
            $type = ($s['type'] ?? '') === 'user' ? 'user' : 'role';
            $sid = (int) ($s['id'] ?? 0);
            $ok = $type === 'user'
                ? $db->value('SELECT u.id FROM users u JOIN company_user cu ON cu.user_id = u.id AND cu.company_id = ? WHERE u.id = ?', [Auth::companyId(), $sid])
                : $db->value('SELECT id FROM roles WHERE id = ?', [$sid]);
            if ($ok) {
                $steps[] = ['type' => $type, 'id' => $sid];
            }
        }
        if (!$steps) {
            throw new HttpException(422, 'Add at least one approval step.');
        }
        $data = ['name' => $name, 'entity' => $entity, 'min_amount' => max(0, round((float) ($in['min_amount'] ?? 0), 2)), 'steps' => json_encode($steps), 'is_active' => !empty($in['is_active']) ? 1 : 0, 'updated_at' => DB::now()];
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            if (!$db->value('SELECT id FROM approval_flows WHERE id = ? AND company_id = ?', [$id, Auth::companyId()])) {
                throw new HttpException(404);
            }
            $db->update('approval_flows', $data, ['id' => $id]);
        } else {
            $id = $db->insert('approval_flows', $data + ['company_id' => Auth::companyId(), 'created_at' => DB::now()]);
        }
        Audit::log('settings.approval_flow', null, $id, ['entity' => $entity, 'steps' => count($steps)]);
        return $this->json(['ok' => true, 'message' => 'Approval chain saved.', 'reload' => true]);
    }

    public function deleteFlow(Request $r, string $id): Response
    {
        $db = App::db();
        if ($db->value("SELECT id FROM approval_requests WHERE flow_id = ? AND status = 'pending' LIMIT 1", [(int) $id])) {
            throw new HttpException(422, 'Requests are still waiting on this chain. Deactivate it instead.');
        }
        $db->query('DELETE FROM approval_flows WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        return $this->json(['ok' => true, 'message' => 'Approval chain deleted.', 'reload' => true]);
    }
}

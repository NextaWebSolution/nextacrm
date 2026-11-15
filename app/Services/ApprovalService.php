<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * Multi-step approval chains for quotations and expenses. A flow applies when the record's amount is at or above
 * its threshold; each step is a role (any active user with that role) or a specific user. The requester can never
 * approve their own request. A quotation cannot be sent and an expense cannot be approved/reimbursed until the
 * chain is complete. Changing the amount of an approved record sends it back for approval.
 */
final class ApprovalService
{
    public const ENTITIES = ['quotes' => 'Quotations', 'expenses' => 'Expenses'];

    public static function amount(string $entity, array $rec): float
    {
        return (float) ($entity === 'quotes' ? ($rec['total'] ?? 0) : ($rec['amount'] ?? 0));
    }

    public static function flowFor(string $entity, array $rec): ?array
    {
        if (!isset(self::ENTITIES[$entity])) {
            return null;
        }
        return App::db()->one('SELECT * FROM approval_flows WHERE company_id = ? AND entity = ? AND is_active = 1 AND min_amount <= ? ORDER BY min_amount DESC, id LIMIT 1', [(int) $rec['company_id'], $entity, self::amount($entity, $rec)]) ?: null;
    }

    public static function required(string $entity, array $rec): bool
    {
        return self::flowFor($entity, $rec) !== null && ($rec['approval_status'] ?? null) !== 'approved';
    }

    public static function current(string $entity, int $id): ?array
    {
        return App::db()->one('SELECT * FROM approval_requests WHERE entity = ? AND record_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1', [$entity, $id, Auth::companyId()]) ?: null;
    }

    /** @return int[] user ids allowed to decide the given step */
    public static function approvers(array $flow, int $step): array
    {
        $steps = json_decode((string) $flow['steps'], true) ?: [];
        $s = $steps[$step] ?? null;
        if (!$s) {
            return [];
        }
        $db = App::db();
        if (($s['type'] ?? '') === 'user') {
            return array_map('intval', $db->column("SELECT u.id FROM users u JOIN company_user cu ON cu.user_id = u.id AND cu.company_id = ? WHERE u.id = ? AND u.status = 'active' AND u.deleted_at IS NULL", [(int) $flow['company_id'], (int) $s['id']]));
        }
        return array_map('intval', $db->column("SELECT u.id FROM users u JOIN company_user cu ON cu.user_id = u.id AND cu.company_id = ? WHERE u.role_id = ? AND u.status = 'active' AND u.deleted_at IS NULL", [(int) $flow['company_id'], (int) ($s['id'] ?? 0)]));
    }

    public static function stepLabel(array $flow, int $step): string
    {
        $s = (json_decode((string) $flow['steps'], true) ?: [])[$step] ?? null;
        if (!$s) {
            return '—';
        }
        $db = App::db();
        return ($s['type'] ?? '') === 'user'
            ? (string) ($db->value('SELECT name FROM users WHERE id = ?', [(int) $s['id']]) ?: 'User')
            : 'Any ' . (string) ($db->value('SELECT name FROM roles WHERE id = ?', [(int) $s['id']]) ?: 'role');
    }

    public static function submit(string $entity, int $id): string
    {
        $svc = RecordService::for($entity);
        $rec = $svc->find($id, 'edit');
        $raw = App::db()->find($svc->table, $id);
        $flow = self::flowFor($entity, $raw);
        if (!$flow) {
            throw new HttpException(422, 'No approval is needed for this amount.');
        }
        if (($raw['approval_status'] ?? null) === 'pending') {
            throw new HttpException(422, 'This is already waiting for approval.');
        }
        if (($raw['approval_status'] ?? null) === 'approved') {
            throw new HttpException(422, 'This is already approved.');
        }
        $db = App::db();
        $reqId = $db->transaction(function () use ($db, $flow, $entity, $id) {
            $rid = $db->insert('approval_requests', ['company_id' => Auth::companyId(), 'flow_id' => (int) $flow['id'], 'entity' => $entity, 'record_id' => $id, 'status' => 'pending', 'current_step' => 0, 'requested_by' => Auth::id(), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
            $db->update($entity, ['approval_status' => 'pending'], ['id' => $id]);
            return $rid;
        });
        self::notifyStep($flow, 0, $entity, $id, $rec['_title']);
        ActivityService::log($entity, $id, 'system', 'Submitted for approval (' . $flow['name'] . ').');
        Audit::log('approval.submitted', $entity, $id, ['request' => $reqId]);
        return 'Submitted for approval.';
    }

    private static function notifyStep(array $flow, int $step, string $entity, int $id, string $title): void
    {
        foreach (self::approvers($flow, $step) as $uid) {
            if ($uid !== Auth::id()) {
                NotificationService::notify($uid, 'approval.request', 'Approval needed: ' . $title, $flow['name'] . ' · step ' . ($step + 1), '/r/' . $entity . '/' . $id);
            }
        }
    }

    public static function canDecide(array $req, ?array $flow = null): bool
    {
        $flow ??= App::db()->find('approval_flows', (int) $req['flow_id']);
        if (!$flow || $req['status'] !== 'pending' || (int) $req['requested_by'] === Auth::id()) {
            return false;
        }
        return in_array(Auth::id(), self::approvers($flow, (int) $req['current_step']), true);
    }

    public static function decide(int $requestId, string $decision, string $comment): string
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $requestId, $decision, $comment) {
            $req = $db->one('SELECT * FROM approval_requests WHERE id = ? AND company_id = ?' . $db->forUpdate(), [$requestId, Auth::companyId()]);
            if (!$req) {
                throw new HttpException(404);
            }
            $flow = $db->find('approval_flows', (int) $req['flow_id']);
            if (!self::canDecide($req, $flow)) {
                throw new HttpException(403, (int) $req['requested_by'] === Auth::id() ? 'You cannot approve your own request.' : 'You are not an approver for this step.');
            }
            if (!in_array($decision, ['approve', 'reject'], true)) {
                throw new HttpException(422, 'Invalid decision.');
            }
            $comment = mb_substr(trim($comment), 0, 2000);
            if ($decision === 'reject' && $comment === '') {
                throw new HttpException(422, 'Please add a reason for rejecting.');
            }
            $step = (int) $req['current_step'];
            $db->insert('approval_actions', ['request_id' => $requestId, 'step' => $step, 'user_id' => Auth::id(), 'decision' => $decision === 'approve' ? 'approved' : 'rejected', 'comment' => $comment ?: null, 'created_at' => DB::now()]);
            $entity = $req['entity'];
            $id = (int) $req['record_id'];
            $title = (string) RecordService::for($entity)->titleOf($db->find($entity, $id) ?? []);
            $steps = json_decode((string) $flow['steps'], true) ?: [];
            if ($decision === 'reject') {
                $db->update('approval_requests', ['status' => 'rejected', 'decided_at' => DB::now(), 'updated_at' => DB::now()], ['id' => $requestId]);
                $db->update($entity, ['approval_status' => 'rejected'] + ($entity === 'expenses' ? ['status' => 'Rejected', 'approved_by' => Auth::id(), 'approved_at' => DB::now()] : []), ['id' => $id]);
                NotificationService::notify((int) $req['requested_by'], 'approval.rejected', 'Rejected: ' . $title, $comment, '/r/' . $entity . '/' . $id);
                ActivityService::log($entity, $id, 'system', 'Approval rejected by ' . Auth::user()['name'] . ': ' . $comment);
                return 'Rejected.';
            }
            if ($step + 1 < count($steps)) {
                $db->update('approval_requests', ['current_step' => $step + 1, 'updated_at' => DB::now()], ['id' => $requestId]);
                ActivityService::log($entity, $id, 'system', 'Approval step ' . ($step + 1) . ' approved by ' . Auth::user()['name'] . '.');
                self::notifyStep($flow, $step + 1, $entity, $id, $title);
                return 'Approved — sent to the next approver.';
            }
            $db->update('approval_requests', ['status' => 'approved', 'decided_at' => DB::now(), 'updated_at' => DB::now()], ['id' => $requestId]);
            $db->update($entity, ['approval_status' => 'approved'] + ($entity === 'expenses' ? ['status' => 'Approved', 'approved_by' => Auth::id(), 'approved_at' => DB::now()] : []), ['id' => $id]);
            NotificationService::notify((int) $req['requested_by'], 'approval.approved', 'Approved: ' . $title, 'All approval steps are complete.', '/r/' . $entity . '/' . $id);
            ActivityService::log($entity, $id, 'system', 'Fully approved (final step by ' . Auth::user()['name'] . ').');
            Audit::log('approval.approved', $entity, $id, ['request' => $requestId]);
            return 'Approved.';
        });
    }

    /** Requests waiting for the current user. */
    public static function inbox(): array
    {
        $out = [];
        $db = App::db();
        foreach ($db->all("SELECT r.*, f.name AS flow_name, f.steps, f.company_id AS fcid FROM approval_requests r JOIN approval_flows f ON f.id = r.flow_id WHERE r.company_id = ? AND r.status = 'pending' ORDER BY r.created_at", [Auth::companyId()]) as $r) {
            $flow = ['steps' => $r['steps'], 'company_id' => $r['fcid']];
            if ((int) $r['requested_by'] !== Auth::id() && in_array(Auth::id(), self::approvers($flow, (int) $r['current_step']), true)) {
                $rec = $db->find($r['entity'], (int) $r['record_id']);
                if ($rec && !$rec['deleted_at']) {
                    $r['title'] = RecordService::for($r['entity'])->titleOf($rec);
                    $r['amount'] = self::amount($r['entity'], $rec);
                    $r['currency'] = $rec['currency'] ?? '';
                    $r['requester'] = (string) $db->value('SELECT name FROM users WHERE id = ?', [(int) $r['requested_by']]);
                    $out[] = $r;
                }
            }
        }
        return $out;
    }

    /** Record hook: block sending/approving until approved; reset approval if the amount changes. */
    public static function guard(string $entity, array &$data, ?array $old, bool $system = false): void
    {
        if (!$old) {
            return;
        }
        $merged = array_merge($old, $data);
        if (($old['approval_status'] ?? null) === 'approved' && abs(self::amount($entity, $merged) - self::amount($entity, $old)) > 0.004 && self::flowFor($entity, $merged)) {
            $data['approval_status'] = null; // amount changed after approval → needs a fresh approval
            $merged['approval_status'] = null;
        }
        if ($system) {
            return;
        }
        $newStatus = $data['status'] ?? null;
        if ($newStatus === null || $newStatus === $old['status']) {
            return;
        }
        $gated = $entity === 'quotes' ? ['Sent', 'Accepted'] : ['Approved', 'Reimbursed'];
        if (in_array($newStatus, $gated, true) && self::required($entity, $merged)) {
            throw new HttpException(422, ($entity === 'quotes' ? 'This quotation must be approved before it is sent.' : 'This expense must go through the approval chain.') . ' Use "Submit for approval".');
        }
    }
}

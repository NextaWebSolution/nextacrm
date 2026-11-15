<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\RecordService;
use App\Services\SequenceService;

final class SequenceController extends Controller
{
    private function find(int $id): array
    {
        $s = App::db()->one('SELECT * FROM email_sequences WHERE id = ? AND company_id = ?', [$id, Auth::companyId()]);
        if (!$s) {
            throw new HttpException(404);
        }
        return $s;
    }

    public function index(Request $r): Response
    {
        $rows = App::db()->all("SELECT s.*, (SELECT COUNT(*) FROM sequence_steps x WHERE x.sequence_id = s.id) AS steps,
            (SELECT COUNT(*) FROM sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = 'active') AS active,
            (SELECT COUNT(*) FROM sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = 'completed') AS completed,
            (SELECT COUNT(*) FROM sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = 'replied') AS replied
            FROM email_sequences s WHERE s.company_id = ? ORDER BY s.name", [Auth::companyId()]);
        return $this->view('sequences/index', ['title' => 'E-mail sequences', 'rows' => $rows]);
    }

    public function edit(Request $r, string $id = '0'): Response
    {
        Permissions::authorize('sequences', (int) $id ? 'edit' : 'create');
        $seq = (int) $id ? $this->find((int) $id) : ['id' => 0, 'name' => '', 'entity' => 'leads', 'is_active' => 1, 'stop_on_reply' => 1];
        $steps = (int) $id ? SequenceService::steps((int) $id) : [['delay_days' => 0, 'subject' => 'Quick intro from {{user.name}}', 'body' => "Hi {{name}},\n\n"]];
        $enrolled = (int) $id ? App::db()->all('SELECT * FROM sequence_enrollments WHERE sequence_id = ? ORDER BY updated_at DESC LIMIT 100', [(int) $id]) : [];
        View::share('breadcrumbs', ['E-mail sequences' => url('/sequences')]);
        return $this->view('sequences/edit', ['title' => $seq['id'] ? $seq['name'] : 'New sequence', 'seq' => $seq, 'steps' => $steps, 'enrolled' => $enrolled]);
    }

    public function save(Request $r, string $id = '0'): Response
    {
        $in = $r->json() ?: $r->all();
        $db = App::db();
        $id = (int) $id;
        Permissions::authorize('sequences', $id ? 'edit' : 'create');
        if ($id) {
            $this->find($id);
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        $entity = (string) ($in['entity'] ?? 'leads');
        if ($name === '' || !isset(SequenceService::ENTITIES[$entity])) {
            throw new HttpException(422, 'Give the sequence a name and choose who it is for.');
        }
        $steps = [];
        foreach (array_slice((array) ($in['steps'] ?? []), 0, 20) as $s) {
            $subject = mb_substr(trim((string) ($s['subject'] ?? '')), 0, 255);
            $body = mb_substr((string) ($s['body'] ?? ''), 0, 20000);
            if ($subject === '' || trim($body) === '') {
                throw new HttpException(422, 'Every step needs a subject and a message.');
            }
            $steps[] = ['delay_days' => max(0, min(365, (int) ($s['delay_days'] ?? 0))), 'subject' => $subject, 'body' => $body];
        }
        if (!$steps) {
            throw new HttpException(422, 'Add at least one step.');
        }
        $data = ['name' => $name, 'entity' => $entity, 'is_active' => !empty($in['is_active']) ? 1 : 0, 'stop_on_reply' => !empty($in['stop_on_reply']) ? 1 : 0, 'updated_at' => DB::now()];
        $db->transaction(function () use ($db, &$id, $data, $steps) {
            if ($id) {
                $db->update('email_sequences', $data, ['id' => $id]);
                $db->delete('sequence_steps', ['sequence_id' => $id]);
            } else {
                $id = $db->insert('email_sequences', $data + ['company_id' => Auth::companyId(), 'created_by' => Auth::id(), 'created_at' => DB::now()]);
            }
            foreach ($steps as $i => $s) {
                $db->insert('sequence_steps', $s + ['sequence_id' => $id, 'sort' => $i]);
            }
        });
        Audit::log('sequence.saved', null, $id, ['steps' => count($steps)]);
        return $this->json(['ok' => true, 'message' => 'Sequence saved.', 'redirect' => url('/sequences/' . $id)]);
    }

    public function delete(Request $r, string $id): Response
    {
        Permissions::authorize('sequences', 'delete');
        $this->find((int) $id);
        $db = App::db();
        $db->delete('sequence_steps', ['sequence_id' => (int) $id]);
        $db->delete('sequence_enrollments', ['sequence_id' => (int) $id]);
        $db->delete('email_sequences', ['id' => (int) $id]);
        return $this->json(['ok' => true, 'message' => 'Sequence deleted.', 'redirect' => url('/sequences')]);
    }

    /** POST /r/{entity}/{id}/sequence — enroll a lead/contact. */
    public function enroll(Request $r, string $entity, string $id): Response
    {
        if (!isset(SequenceService::ENTITIES[$entity])) {
            throw new HttpException(404);
        }
        Permissions::authorize('sequences', 'create');
        $rec = RecordService::for($entity)->find((int) $id);
        if (!Permissions::can($entity, 'edit', $rec)) {
            throw new HttpException(403);
        }
        $msg = SequenceService::enroll($r->int('sequence_id'), $entity, (int) $id);
        return $this->json(['ok' => true, 'message' => $msg, 'reload' => true]);
    }

    public function stop(Request $r, string $id): Response
    {
        Permissions::authorize('sequences', 'edit');
        SequenceService::stop((int) $id);
        return $this->json(['ok' => true, 'message' => 'Enrolment stopped.', 'reload' => true]);
    }

    // ── Public unsubscribe (two-step so link scanners can't unsubscribe people by prefetching) ──
    public function unsubscribeForm(Request $r, string $token): Response
    {
        $p = SequenceService::parseToken($token);
        if (!$p) {
            throw new HttpException(404);
        }
        Session::start();
        return Response::html(View::partial('public/unsubscribe', ['token' => $token, 'email' => $p[1], 'done' => false]));
    }

    public function unsubscribe(Request $r, string $token): Response
    {
        $p = SequenceService::parseToken($token);
        if (!$p) {
            throw new HttpException(404);
        }
        SequenceService::unsubscribe($p[0], $p[1]);
        return Response::html(View::partial('public/unsubscribe', ['token' => $token, 'email' => $p[1], 'done' => true]));
    }
}

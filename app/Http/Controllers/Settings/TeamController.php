<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\LookupService;

final class TeamController extends Controller
{
    public function index(Request $r): Response
    {
        $db = App::db();
        $teams = $db->all('SELECT * FROM teams WHERE company_id = ? ORDER BY name', [Auth::companyId()]);
        foreach ($teams as &$t) {
            $t['members'] = array_map('intval', $db->column('SELECT user_id FROM team_user WHERE team_id = ?', [$t['id']]));
        }
        return $this->view('settings/teams', ['title' => 'Teams', 'tab' => 'teams', 'teams' => $teams, 'users' => LookupService::companyUsers()]);
    }

    public function save(Request $r): Response
    {
        $db = App::db();
        $id = $r->int('id');
        $name = mb_substr(trim($r->str('name')), 0, 150);
        if ($name === '') {
            throw new HttpException(422, 'Team name is required.');
        }
        if ($id && !$db->value('SELECT id FROM teams WHERE id = ? AND company_id = ?', [$id, Auth::companyId()])) {
            throw new HttpException(404);
        }
        $valid = array_column(LookupService::companyUsers(), 'id');
        $db->transaction(function () use ($db, &$id, $name, $r, $valid) {
            if ($id) {
                $db->update('teams', ['name' => $name, 'description' => mb_substr($r->str('description'), 0, 190) ?: null, 'updated_at' => DB::now()], ['id' => $id]);
            } else {
                $id = $db->insert('teams', ['company_id' => Auth::companyId(), 'name' => $name, 'description' => mb_substr($r->str('description'), 0, 190) ?: null, 'created_at' => DB::now(), 'updated_at' => DB::now()]);
            }
            $db->delete('team_user', ['team_id' => $id]);
            foreach (array_unique(array_map('intval', (array) $r->input('members', []))) as $u) {
                if (in_array($u, $valid, true)) {
                    $db->insert('team_user', ['team_id' => $id, 'user_id' => $u]);
                }
            }
        });
        return $this->redirect('/settings/teams', 'success', 'Team saved.');
    }

    public function destroy(Request $r, string $id): Response
    {
        $db = App::db();
        if (!$db->value('SELECT id FROM teams WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()])) {
            throw new HttpException(404);
        }
        $db->delete('team_user', ['team_id' => (int) $id]);
        $db->delete('teams', ['id' => (int) $id]);
        return $this->json(['ok' => true, 'message' => 'Team deleted', 'reload' => true]);
    }
}

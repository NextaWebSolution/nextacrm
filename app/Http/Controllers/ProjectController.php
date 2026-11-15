<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectService;

final class ProjectController extends Controller
{
    public function addMember(Request $r, string $id): Response
    {
        $p = $this->service('projects')->find((int) $id, 'edit');
        $uid = $r->int('user_id');
        if (!App::db()->value('SELECT user_id FROM company_user WHERE user_id = ? AND company_id = ?', [$uid, Auth::companyId()])) {
            throw new HttpException(422, 'Choose a team member.');
        }
        ProjectService::addMember((int) $p['id'], $uid, mb_substr($r->str('role'), 0, 50) ?: null);
        return $this->json(['ok' => true, 'message' => 'Member added', 'reload' => true]);
    }

    public function removeMember(Request $r, string $id, string $uid): Response
    {
        $p = $this->service('projects')->find((int) $id, 'edit');
        if ((int) $uid === (int) $p['assigned_to']) {
            throw new HttpException(422, 'The project manager cannot be removed. Change the manager first.');
        }
        App::db()->delete('project_members', ['project_id' => (int) $id, 'user_id' => (int) $uid]);
        return $this->json(['ok' => true, 'message' => 'Member removed', 'reload' => true]);
    }
}

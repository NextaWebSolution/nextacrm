<?php
declare(strict_types=1);

namespace App\Http\Controllers\Settings;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;

final class RoleController extends Controller
{
    public function index(Request $r): Response
    {
        $roles = App::db()->all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS user_count FROM roles r ORDER BY r.is_admin DESC, r.name');
        return $this->view('settings/roles', ['title' => 'Roles & permissions', 'tab' => 'roles', 'roles' => $roles]);
    }

    public function edit(Request $r, string $id): Response
    {
        $role = $id === 'new' ? ['id' => null, 'name' => '', 'description' => '', 'is_admin' => 0, 'is_system' => 0, 'permissions' => '{}'] : App::db()->find('roles', (int) $id);
        if (!$role) {
            throw new HttpException(404);
        }
        return $this->view('settings/role_edit', ['title' => $role['id'] ? 'Edit role' : 'New role', 'tab' => 'roles', 'role' => $role, 'perms' => json_decode((string) $role['permissions'], true) ?: [], 'modules' => Permissions::modules()]);
    }

    private function collect(Request $r): array
    {
        $in = (array) $r->input('perm', []);
        $out = [];
        foreach (Permissions::modules() as $mod => $label) {
            $p = (array) ($in[$mod] ?? []);
            foreach (Permissions::ACTIONS as $a) {
                if (in_array($a, Permissions::SCOPED, true)) {
                    $v = (string) ($p[$a] ?? 'none');
                    $out[$mod][$a] = in_array($v, Permissions::LEVELS, true) ? $v : 'none';
                } else {
                    $out[$mod][$a] = !empty($p[$a]);
                }
            }
        }
        return $out;
    }

    public function store(Request $r): Response
    {
        $name = mb_substr(trim($r->str('name')), 0, 100);
        if ($name === '') {
            throw new HttpException(422, 'Role name is required.');
        }
        $id = App::db()->insert('roles', ['name' => $name, 'description' => mb_substr($r->str('description'), 0, 190) ?: null, 'is_admin' => 0, 'is_system' => 0, 'permissions' => json_encode($this->collect($r)), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        Audit::log('role.created', 'roles', $id);
        return $this->redirect('/settings/roles', 'success', 'Role created.');
    }

    public function update(Request $r, string $id): Response
    {
        $role = App::db()->find('roles', (int) $id);
        if (!$role) {
            throw new HttpException(404);
        }
        if ($role['is_admin']) {
            App::db()->update('roles', ['name' => mb_substr(trim($r->str('name')), 0, 100) ?: $role['name'], 'description' => mb_substr($r->str('description'), 0, 190) ?: null, 'updated_at' => DB::now()], ['id' => $role['id']]);
            return $this->redirect('/settings/roles', 'success', 'Role updated. Administrator permissions cannot be restricted.');
        }
        $perms = $this->collect($r);
        App::db()->update('roles', ['name' => mb_substr(trim($r->str('name')), 0, 100) ?: $role['name'], 'description' => mb_substr($r->str('description'), 0, 190) ?: null, 'permissions' => json_encode($perms), 'updated_at' => DB::now()], ['id' => $role['id']]);
        Audit::log('role.updated', 'roles', (int) $role['id'], ['permissions' => $perms]);
        return $this->redirect('/settings/roles', 'success', 'Permissions saved.');
    }

    public function destroy(Request $r, string $id): Response
    {
        $role = App::db()->find('roles', (int) $id);
        if (!$role || $role['is_admin'] || $role['is_system']) {
            throw new HttpException(422, 'This role cannot be deleted.');
        }
        if (App::db()->value('SELECT COUNT(*) FROM users WHERE role_id = ? AND deleted_at IS NULL', [$role['id']])) {
            throw new HttpException(422, 'Move users to another role before deleting this one.');
        }
        App::db()->delete('roles', ['id' => $role['id']]);
        Audit::log('role.deleted', 'roles', (int) $role['id']);
        return $this->json(['ok' => true, 'message' => 'Role deleted', 'reload' => true]);
    }
}

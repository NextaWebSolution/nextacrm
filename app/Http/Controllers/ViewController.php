<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Saved views (filters + sort + columns + layout), private or shared. */
final class ViewController extends Controller
{
    public function store(Request $r, string $entity): Response
    {
        $this->service($entity);
        $name = mb_substr(trim($r->str('name')), 0, 100);
        if ($name === '') {
            throw new HttpException(422, 'Give the view a name.');
        }
        $query = $r->str('query');
        parse_str(ltrim($query, '?'), $q);
        $config = array_intersect_key($q, array_flip(['q', 'f', 'sort', 'dir', 'view', 'scope', 'pipeline']));
        $shared = (bool) $r->input('shared', false) && (Auth::user()['is_admin'] || can($this->service($entity)->module, 'manage'));
        $id = App::db()->insert('saved_views', ['company_id' => Auth::companyId(), 'user_id' => Auth::id(), 'entity' => $entity, 'name' => $name, 'config' => json_encode($config), 'is_shared' => $shared ? 1 : 0, 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        return $this->json(['ok' => true, 'message' => 'View saved', 'id' => $id]);
    }

    public function destroy(Request $r, string $id): Response
    {
        $v = App::db()->one('SELECT * FROM saved_views WHERE id = ? AND company_id = ?', [(int) $id, Auth::companyId()]);
        if (!$v || ((int) $v['user_id'] !== Auth::id() && empty(Auth::user()['is_admin']))) {
            throw new HttpException(404);
        }
        App::db()->delete('saved_views', ['id' => (int) $id]);
        return $this->json(['ok' => true, 'message' => 'View deleted']);
    }
}

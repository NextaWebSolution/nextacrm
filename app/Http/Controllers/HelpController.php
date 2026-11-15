<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** Public help centre: published knowledge-base articles marked "Show in public help centre". */
final class HelpController extends Controller
{
    private function company(): array
    {
        $db = App::db();
        $id = (int) setting('help.company_id', 0);
        $c = $id ? $db->find('companies', $id) : null;
        return $c ?: ($db->one('SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1') ?? []);
    }

    public function index(Request $r): Response
    {
        $c = $this->company();
        $q = mb_substr(trim($r->str('q')), 0, 100);
        $db = App::db();
        $sql = "SELECT id, title, slug, category, body, updated_at FROM kb_articles WHERE company_id = ? AND is_public = 1 AND status = 'Published' AND deleted_at IS NULL";
        $p = [(int) ($c['id'] ?? 0)];
        if ($q !== '') {
            $sql .= ' AND (title LIKE ?' . $db->likeEscape() . ' OR body LIKE ?' . $db->likeEscape() . ')';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $like, $like);
        }
        $rows = $db->all($sql . ' ORDER BY category, title LIMIT 300', $p);
        $groups = [];
        foreach ($rows as $a) {
            $groups[$a['category'] ?: 'General'][] = $a;
        }
        return Response::html(View::partial('public/help', ['company' => $c, 'groups' => $groups, 'q' => $q, 'count' => count($rows)]))->header('Cache-Control', 'public, max-age=300');
    }

    public function article(Request $r, string $ref): Response
    {
        if (!preg_match('/^(\d+)/', $ref, $m)) {
            throw new HttpException(404);
        }
        $id = $m[1];
        $c = $this->company();
        $a = App::db()->one("SELECT * FROM kb_articles WHERE id = ? AND company_id = ? AND is_public = 1 AND status = 'Published' AND deleted_at IS NULL", [(int) $id, (int) ($c['id'] ?? 0)]);
        if (!$a) {
            throw new HttpException(404);
        }
        App::db()->query('UPDATE kb_articles SET views = views + 1 WHERE id = ?', [$a['id']]);
        $related = App::db()->all("SELECT id, title, slug FROM kb_articles WHERE company_id = ? AND category = ? AND id <> ? AND is_public = 1 AND status = 'Published' AND deleted_at IS NULL ORDER BY views DESC LIMIT 5", [$a['company_id'], (string) $a['category'], $a['id']]);
        return Response::html(View::partial('public/help_article', ['company' => $c, 'a' => $a, 'related' => $related]))->header('Cache-Control', 'public, max-age=300');
    }
}

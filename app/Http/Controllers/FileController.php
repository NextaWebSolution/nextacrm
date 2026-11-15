<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\FileService;
use App\Services\LookupService;

final class FileController extends Controller
{
    public function index(Request $r): Response
    {
        $folderId = (int) ($r->query['folder'] ?? 0);
        $db = App::db();
        $cid = Auth::companyId();
        $folder = $folderId ? $db->one('SELECT * FROM folders WHERE id = ? AND company_id = ?', [$folderId, $cid]) : null;
        if ($folderId && !$folder) {
            throw new HttpException(404);
        }
        $q = trim((string) ($r->query['q'] ?? ''));
        $params = [$cid];
        $sql = 'SELECT * FROM files WHERE company_id = ? AND deleted_at IS NULL';
        if ($q !== '') {
            $sql .= ' AND name LIKE ?' . $db->likeEscape();
            $params[] = DB::like($q);
        } else {
            $sql .= $folderId ? ' AND folder_id = ? AND entity IS NULL' : ' AND folder_id IS NULL AND entity IS NULL';
            if ($folderId) {
                $params[] = $folderId;
            }
        }
        if (Permissions::level('files', 'view') === 'own') {
            $sql .= ' AND uploaded_by = ?';
            $params[] = Auth::id();
        }
        $files = $db->all($sql . ' ORDER BY id DESC LIMIT 500', $params);
        if ($q !== '') {
            $files = array_values(array_filter($files, function ($f) {
                try { FileService::authorize($f, 'view'); return true; } catch (\Throwable) { return false; }
            }));
        }
        $users = LookupService::users(array_column($files, 'uploaded_by'));
        $folders = $q === '' ? $db->all('SELECT f.*, (SELECT COUNT(*) FROM files x WHERE x.folder_id = f.id AND x.deleted_at IS NULL) AS file_count FROM folders f WHERE f.company_id = ? AND ' . ($folderId ? 'f.parent_id = ?' : 'f.parent_id IS NULL') . ' ORDER BY f.name', $folderId ? [$cid, $folderId] : [$cid]) : [];
        $crumbs = [];
        $p = $folder;
        while ($p) {
            array_unshift($crumbs, $p);
            $p = $p['parent_id'] ? $db->one('SELECT * FROM folders WHERE id = ?', [$p['parent_id']]) : null;
        }
        $usage = $db->one('SELECT COUNT(*) AS c, COALESCE(SUM(size),0) AS s FROM files WHERE company_id = ? AND deleted_at IS NULL', [$cid]);
        return $this->view('files/index', ['title' => 'Files', 'files' => $files, 'folders' => $folders, 'folder' => $folder, 'crumbs' => $crumbs, 'users' => $users, 'q' => $q, 'usage' => $usage]);
    }

    public function createFolder(Request $r): Response
    {
        $name = mb_substr(trim($r->str('name')), 0, 150);
        if ($name === '') {
            throw new HttpException(422, 'Folder name is required.');
        }
        $parent = (int) $r->input('parent_id', 0) ?: null;
        if ($parent && !App::db()->value('SELECT id FROM folders WHERE id = ? AND company_id = ?', [$parent, Auth::companyId()])) {
            throw new HttpException(422, 'Invalid parent folder.');
        }
        App::db()->insert('folders', ['company_id' => Auth::companyId(), 'parent_id' => $parent, 'name' => $name, 'created_by' => Auth::id(), 'created_at' => DB::now()]);
        return $this->done($r, 'Folder created', '/files' . ($parent ? '?folder=' . $parent : ''));
    }

    public function upload(Request $r): Response
    {
        Permissions::authorize('files', 'create');
        $folder = (int) $r->input('folder_id', 0) ?: null;
        if ($folder && !App::db()->value('SELECT id FROM folders WHERE id = ? AND company_id = ?', [$folder, Auth::companyId()])) {
            throw new HttpException(422, 'Invalid folder.');
        }
        $f = $r->file('file');
        if (!$f) {
            throw new HttpException(422, 'Choose a file to upload.');
        }
        $row = FileService::upload($f, null, null, $folder);
        return $this->json(['ok' => true, 'message' => 'Uploaded ' . $row['name'], 'file' => ['id' => $row['id'], 'name' => $row['name']]]);
    }

    public function uploadToRecord(Request $r, string $entity, string $id): Response
    {
        $svc = $this->service($entity);
        $svc->find((int) $id, 'view');
        if (!can('files', 'create') && !can($svc->module, 'edit')) {
            throw new HttpException(403);
        }
        $f = $r->file('file');
        if (!$f) {
            throw new HttpException(422, 'Choose a file to upload.');
        }
        $row = FileService::upload($f, $entity, (int) $id);
        $row['uploader'] = Auth::user()['name'];
        return $this->json(['ok' => true, 'message' => 'Attached ' . $row['name'], 'html' => View::partial('records/_file_item', ['f' => $row, 'canDelete' => true])]);
    }

    public function download(Request $r, string $id): Response
    {
        return FileService::download((int) $id, false);
    }

    public function inline(Request $r, string $id): Response
    {
        return FileService::download((int) $id, true);
    }

    public function destroy(Request $r, string $id): Response
    {
        FileService::delete((int) $id);
        return $this->json(['ok' => true, 'message' => 'File deleted']);
    }
}

<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\Request;
use App\Core\Response;
use App\Services\SearchService;

final class SearchController extends Controller
{
    public function search(Request $r): Response
    {
        $only = (string) ($r->query['in'] ?? '') ?: null;
        return $this->json(['groups' => SearchService::search((string) ($r->query['q'] ?? ''), $only, $only ? 20 : 5)]);
    }
}

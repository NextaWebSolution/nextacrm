<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\EntityRegistry;
use App\Services\RecordService;
use App\Services\SalesService;
use App\Services\SearchService;

final class RecordApiController
{
    private function svc(string $entity): RecordService
    {
        if (!EntityRegistry::exists($entity)) {
            throw new HttpException(404, 'Unknown resource.');
        }
        $svc = RecordService::for($entity);
        if (!module_enabled($svc->module)) {
            throw new HttpException(404, 'Unknown resource.');
        }
        return $svc;
    }

    private function shape(RecordService $svc, array $row): array
    {
        $labels = $row['_labels'] ?? [];
        unset($row['_labels'], $row['_title'], $row['_stage'], $row['deleted_at'], $row['company_id'], $row['public_token']);
        if ($svc->entity === 'quotes' || $svc->entity === 'invoices') {
            $row['items'] = array_map(fn ($i) => array_diff_key($i, array_flip(['parent_type', 'parent_id'])), SalesService::items($svc->entity === 'quotes' ? 'quote' : 'invoice', (int) $row['id']));
        }
        $row['_links'] = ['self' => full_url('/api/v1/' . $svc->entity . '/' . $row['id']), 'html' => full_url('/r/' . $svc->entity . '/' . $row['id'])];
        if ($labels) {
            $row['_labels'] = $labels;
        }
        return $row;
    }

    public function me(Request $r): Response
    {
        $u = Auth::user();
        return Response::json(['data' => ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role_name'], 'company_id' => Auth::companyId(), 'scope' => Auth::apiToken()['scope'] ?? null]]);
    }

    public function meta(Request $r): Response
    {
        $out = [];
        foreach (EntityRegistry::all() as $key => $d) {
            if (!Permissions::can($d['module'], 'view') || !module_enabled($d['module'])) {
                continue;
            }
            $def = EntityRegistry::get($key);
            $fields = [];
            foreach (EntityRegistry::visibleFields($def) as $n => $f) {
                $fields[$n] = array_filter(['type' => $f['type'], 'label' => $f['label'], 'required' => !empty($f['required']), 'readonly' => !empty($f['readonly']) || !empty($f['system']), 'relation' => $f['relation'] ?? null, 'options' => in_array($f['type'], ['select', 'multiselect'], true) ? array_keys(EntityRegistry::options($f)) : null], fn ($v) => $v !== null && $v !== false);
            }
            $out[$key] = ['label' => $d['label'], 'fields' => $fields];
        }
        return Response::json(['data' => $out]);
    }

    public function search(Request $r): Response
    {
        return Response::json(['data' => SearchService::search((string) ($r->query['q'] ?? ''), null, 10)]);
    }

    public function index(Request $r, string $entity): Response
    {
        $svc = $this->svc($entity);
        Permissions::authorize($svc->module, 'view');
        $filters = [];
        foreach ((array) ($r->query['filter'] ?? []) as $field => $spec) {
            if (!is_string($field)) {
                continue;
            }
            if (is_array($spec)) {
                foreach ($spec as $op => $v) {
                    $filters[] = ['field' => $field, 'op' => (string) $op, 'value' => is_string($v) && str_contains($v, ',') && $op === 'in' ? explode(',', $v) : $v];
                }
            } else {
                $filters[] = ['field' => $field, 'op' => 'eq', 'value' => $spec];
            }
        }
        $sort = (string) ($r->query['sort'] ?? '');
        $dir = 'asc';
        if (str_starts_with($sort, '-')) {
            $sort = substr($sort, 1);
            $dir = 'desc';
        }
        $res = $svc->list(['q' => (string) ($r->query['q'] ?? ''), 'filters' => $filters, 'sort' => $sort, 'dir' => $sort ? $dir : '', 'page' => (int) ($r->query['page'] ?? 1), 'per_page' => (int) ($r->query['per_page'] ?? 25), 'max_per_page' => 100]);
        return Response::json([
            'data' => array_map(fn ($row) => $this->shape($svc, $row), $res['rows']),
            'meta' => ['total' => $res['total'], 'page' => $res['page'], 'per_page' => $res['per_page'], 'pages' => $res['pages']],
        ]);
    }

    public function show(Request $r, string $entity, string $id): Response
    {
        $svc = $this->svc($entity);
        return Response::json(['data' => $this->shape($svc, $svc->find((int) $id))]);
    }

    public function store(Request $r, string $entity): Response
    {
        $svc = $this->svc($entity);
        $input = $r->json();
        if (!$input) {
            throw new HttpException(400, 'Send a JSON body with Content-Type: application/json.');
        }
        if ($entity === 'quotes' || $entity === 'invoices') {
            $row = SalesService::save($entity === 'quotes' ? 'quote' : 'invoice', null, $input);
        } else {
            $row = $svc->create($input);
        }
        return Response::json(['data' => $this->shape($svc, $row), 'warnings' => $svc->warnings], 201);
    }

    public function update(Request $r, string $entity, string $id): Response
    {
        $svc = $this->svc($entity);
        $input = $r->json();
        if (($entity === 'quotes' || $entity === 'invoices') && isset($input['items'])) {
            $row = SalesService::save($entity === 'quotes' ? 'quote' : 'invoice', (int) $id, $input);
        } else {
            $row = $svc->update((int) $id, $input);
        }
        return Response::json(['data' => $this->shape($svc, $row), 'warnings' => $svc->warnings]);
    }

    public function destroy(Request $r, string $entity, string $id): Response
    {
        $this->svc($entity)->delete((int) $id);
        return new Response('', 204, ['Content-Type' => 'application/json']);
    }

    /** OpenAPI 3.0 document generated from entity metadata. */
    public function openapi(Request $r): Response
    {
        return Response::json(self::spec());
    }

    public static function spec(): array
    {
        $typeMap = fn (array $f) => match ($f['type']) {
            'number', 'relation', 'user', 'pipeline', 'stage', 'tax', 'duration', 'percent' => ['type' => 'integer'],
            'decimal', 'money' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'select' => ['type' => 'string', 'enum' => array_map('strval', array_keys(EntityRegistry::options($f)))],
            default => ['type' => 'string'],
        };
        $paths = [];
        $schemas = ['Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => ['code' => ['type' => 'integer'], 'message' => ['type' => 'string'], 'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]]]]]];
        foreach (EntityRegistry::all() as $key => $d) {
            $name = str_replace(' ', '', ucwords(str_replace('_', ' ', $d['singular'])));
            $props = ['id' => ['type' => 'integer', 'readOnly' => true]];
            $required = [];
            foreach ($d['fields'] as $n => $f) {
                if (!empty($f['sensitive']) || $f['type'] === 'hidden' && !in_array($n, ['related_entity', 'related_id'], true)) {
                    continue;
                }
                $p = $typeMap($f) + ['description' => $f['label'] . (isset($f['relation']) ? ' (ID of ' . $f['relation'] . ')' : '')];
                if (!empty($f['readonly']) || !empty($f['system'])) {
                    $p['readOnly'] = true;
                }
                $props[$n] = $p;
                if (!empty($f['required'])) {
                    $required[] = $n;
                }
            }
            $schemas[$name] = ['type' => 'object', 'properties' => $props] + ($required ? ['required' => $required] : []);
            $ref = ['$ref' => '#/components/schemas/' . $name];
            $err = ['description' => 'Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
            $paths['/' . $key] = [
                'get' => ['tags' => [$d['label']], 'summary' => 'List ' . strtolower($d['label']), 'parameters' => [
                    ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Full-text search'],
                    ['name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'explode' => true, 'schema' => ['type' => 'object'], 'description' => 'filter[field]=value or filter[field][op]=value; ops: eq, neq, contains, gt, gte, lt, lte, in, empty, not_empty'],
                    ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Field name; prefix with - for descending'],
                    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 100]],
                ], 'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $ref], 'meta' => ['type' => 'object']]]]]], '401' => $err, '403' => $err, '429' => $err]],
                'post' => ['tags' => [$d['label']], 'summary' => 'Create ' . strtolower($d['singular']), 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $ref]]], 'responses' => ['201' => ['description' => 'Created'], '422' => $err]],
            ];
            $paths['/' . $key . '/{id}'] = [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'get' => ['tags' => [$d['label']], 'summary' => 'Get ' . strtolower($d['singular']), 'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => $ref]]]]], '404' => $err]],
                'put' => ['tags' => [$d['label']], 'summary' => 'Update ' . strtolower($d['singular']) . ' (partial updates allowed)', 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $ref]]], 'responses' => ['200' => ['description' => 'OK'], '422' => $err]],
                'delete' => ['tags' => [$d['label']], 'summary' => 'Delete ' . strtolower($d['singular']), 'responses' => ['204' => ['description' => 'Deleted']]],
            ];
        }
        $paths['/me'] = ['get' => ['tags' => ['Account'], 'summary' => 'Current token owner', 'responses' => ['200' => ['description' => 'OK']]]];
        $paths['/meta'] = ['get' => ['tags' => ['Account'], 'summary' => 'Entities and fields visible to the token', 'responses' => ['200' => ['description' => 'OK']]]];
        $paths['/search'] = ['get' => ['tags' => ['Account'], 'summary' => 'Global search', 'parameters' => [['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK']]]];
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => setting('app.name', 'NextaCRM') . ' REST API', 'version' => App::version(), 'description' => "Authenticate with `Authorization: Bearer <token>` (create tokens in Settings → API & Webhooks). Read-only tokens can only call GET. Rate limit: 120 requests/minute per token. Errors use `{\"error\":{\"code\",\"message\",\"fields\"}}`.\n\nWebhooks are signed: `X-Nexta-Signature: sha256=HMAC_SHA256(secret, X-Nexta-Timestamp + '.' + body)`."],
            'servers' => [['url' => full_url('/api/v1')]],
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']], 'schemas' => $schemas],
            'security' => [['bearer' => []]],
            'paths' => $paths,
        ];
    }

    public function docs(Request $r): Response
    {
        return Response::html(View::partial('public/api_docs', ['spec' => self::spec()]));
    }
}

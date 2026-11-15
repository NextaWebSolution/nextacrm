<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Events;
use App\Core\HttpException;
use App\Core\Permissions;

/**
 * Metadata-driven record engine. Every read and write of business records goes through here so that
 * tenant isolation (company_id), permission scope (own/team/all), validation and auditing are enforced
 * in one place.
 */
final class RecordService
{
    public array $def;
    public string $table;
    public string $module;
    private DB $db;
    /** Warnings surfaced to the UI (e.g. duplicate detection). */
    public array $warnings = [];

    public const FILTER_OPS = ['eq', 'neq', 'contains', 'not_contains', 'starts', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'is_true', 'is_false', 'today', 'overdue', 'this_week', 'this_month', 'last_days', 'next_days', 'me'];

    public function __construct(public string $entity)
    {
        $this->def = EntityRegistry::get($entity);
        $this->table = $this->def['table'];
        $this->module = $this->def['module'];
        $this->db = App::db();
    }

    public static function for(string $entity): self
    {
        return new self($entity);
    }

    public function hasAssigned(): bool
    {
        return (bool) $this->def['assignable'];
    }

    // ───────────────────────────── Reading ─────────────────────────────

    /** Base WHERE (tenant + soft delete + permission scope) for alias t. */
    public function baseWhere(string $action = 'view'): array
    {
        [$scope, $sp] = Permissions::scopeSql($this->module, $action, 't', $this->hasAssigned());
        $level = Permissions::level($this->module, $action);
        // Project members can see the project, its tasks and milestones even with own/team scope.
        if ($action === 'view' && in_array($level, ['own', 'team'], true) && in_array($this->entity, ['projects', 'tasks', 'milestones'], true)) {
            $ids = ProjectService::memberProjectIds();
            if ($ids) {
                $col = $this->entity === 'projects' ? 't.id' : 't.project_id';
                $scope = "($scope OR $col IN (" . implode(',', $ids) . '))';
            }
        }
        $where = ['t.deleted_at IS NULL', 't.company_id = :_cid', $scope];
        return [$where, ['_cid' => Auth::companyId()] + $sp];
    }

    /**
     * @param array{q?:string, filters?:array, sort?:string, dir?:string, page?:int, per_page?:int, ids?:array, related?:array} $p
     */
    public function list(array $p = []): array
    {
        [$where, $params] = $this->baseWhere('view');
        $this->applySearch($p['q'] ?? '', $where, $params);
        foreach ((array) ($p['filters'] ?? []) as $i => $f) {
            if (is_array($f)) {
                $this->applyFilter($f, $i, $where, $params);
            }
        }
        if (!empty($p['ids']) && is_array($p['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $p['ids'])));
            $where[] = $ids ? 't.id IN (' . implode(',', $ids) . ')' : '1=0';
        }
        if (!empty($p['related']) && is_array($p['related'])) {
            $this->applyRelated($p['related'], $where, $params);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {$this->q($this->table)} t WHERE $whereSql", $params);

        [$sortCol, $dir] = $this->resolveSort($p['sort'] ?? null, $p['dir'] ?? null);
        $perPage = max(1, min((int) ($p['per_page'] ?? setting('app.per_page', 25)), (int) ($p['max_per_page'] ?? 200)));
        $page = max(1, (int) ($p['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $order = $sortCol . ' ' . $dir . ', t.id ' . $dir;
        if (!empty($this->def['sortable_board']) && ($p['board'] ?? false)) {
            $order = 't.sort ASC, ' . $order;
        }

        $rows = $this->db->all("SELECT t.* FROM {$this->q($this->table)} t WHERE $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset", $params);

        $aggregates = [];
        foreach ($this->def['fields'] as $name => $f) {
            if (($f['aggregate'] ?? null) === 'sum' && empty($f['custom'])) {
                $aggregates[$name] = (float) $this->db->value("SELECT COALESCE(SUM(t.{$this->q($name)}),0) FROM {$this->q($this->table)} t WHERE $whereSql", $params);
            }
        }

        return [
            'rows' => $this->decorate($rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) max(1, ceil($total / $perPage)),
            'aggregates' => $aggregates,
        ];
    }

    /** Group counts for kanban columns. */
    public function groupCounts(string $field, array $p = []): array
    {
        if (!isset($this->def['fields'][$field]) || !empty($this->def['fields'][$field]['custom'])) {
            return [];
        }
        [$where, $params] = $this->baseWhere('view');
        $this->applySearch($p['q'] ?? '', $where, $params);
        foreach ((array) ($p['filters'] ?? []) as $i => $f) {
            if (is_array($f)) {
                $this->applyFilter($f, $i, $where, $params);
            }
        }
        $col = 't.' . $this->q($field);
        $sumField = null;
        foreach ($this->def['fields'] as $n => $fd) {
            if (($fd['aggregate'] ?? null) === 'sum' && empty($fd['custom'])) {
                $sumField = $n;
                break;
            }
        }
        $sum = $sumField ? ", COALESCE(SUM(t.{$this->q($sumField)}),0) AS total" : ', 0 AS total';
        $rows = $this->db->all("SELECT $col AS g, COUNT(*) AS c $sum FROM {$this->q($this->table)} t WHERE " . implode(' AND ', $where) . " GROUP BY $col", $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['g']] = ['count' => (int) $r['c'], 'total' => (float) $r['total']];
        }
        return $out;
    }

    public function find(int $id, string $action = 'view'): array
    {
        $row = $this->db->one("SELECT t.* FROM {$this->q($this->table)} t WHERE t.id = :id AND t.company_id = :cid AND t.deleted_at IS NULL", ['id' => $id, 'cid' => Auth::companyId()]);
        if (!$row) {
            throw new HttpException(404);
        }
        if (!Permissions::can($this->module, $action, $row) && !$this->memberAccess($row, $action)) {
            throw new HttpException(403);
        }
        return $this->decorate([$row])[0];
    }

    private function memberAccess(array $row, string $action): bool
    {
        if ($action !== 'view' || Permissions::level($this->module, 'view') === 'none' || !in_array($this->entity, ['projects', 'tasks', 'milestones'], true)) {
            return false;
        }
        $pid = (int) ($this->entity === 'projects' ? $row['id'] : $row['project_id']);
        return $pid > 0 && in_array($pid, ProjectService::memberProjectIds(), true);
    }

    public function exists(int $id): bool
    {
        return (bool) $this->db->value("SELECT id FROM {$this->q($this->table)} WHERE id = ? AND company_id = ? AND deleted_at IS NULL", [$id, Auth::companyId()]);
    }

    private function applySearch(string $q, array &$where, array &$params): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }
        $parts = [];
        $esc = $this->db->likeEscape();
        foreach ($this->def['search'] as $i => $col) {
            if (isset($this->def['fields'][$col]) && empty($this->def['fields'][$col]['custom'])) {
                $parts[] = "t.{$this->q($col)} LIKE :_q$i$esc";
                $params["_q$i"] = DB::like($q);
            }
        }
        if (ctype_digit($q)) {
            $parts[] = 't.id = :_qid';
            $params['_qid'] = (int) $q;
        }
        if ($parts) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    private function applyRelated(array $rel, array &$where, array &$params): void
    {
        if (!empty($rel['morph'])) {
            if (EntityRegistry::exists((string) $rel['entity'])) {
                $where[] = 't.related_entity = :_re AND t.related_id = :_rid';
                $params['_re'] = (string) $rel['entity'];
                $params['_rid'] = (int) $rel['id'];
            }
            return;
        }
        $fk = (string) ($rel['fk'] ?? '');
        if (isset($this->def['fields'][$fk])) {
            $where[] = "t.{$this->q($fk)} = :_relid";
            $params['_relid'] = (int) $rel['id'];
        }
    }

    /** Filter = [field, op, value, value2]. Field and op are validated against metadata. */
    public function applyFilter(array $f, int|string $i, array &$where, array &$params): void
    {
        $field = (string) ($f['field'] ?? $f[0] ?? '');
        $op = (string) ($f['op'] ?? $f[1] ?? 'eq');
        $value = $f['value'] ?? $f[2] ?? null;
        $value2 = $f['value2'] ?? $f[3] ?? null;
        if (!isset($this->def['fields'][$field]) || !in_array($op, self::FILTER_OPS, true)) {
            return;
        }
        $fd = $this->def['fields'][$field];
        if (!empty($fd['sensitive']) && !can($this->module, $fd['sensitive'])) {
            return;
        }
        $col = !empty($fd['custom']) ? $this->db->jsonGet('t.custom', $field) : 't.' . $this->q($field);
        $k = '_f' . preg_replace('/\W/', '', (string) $i);
        $esc = $this->db->likeEscape();
        $isDateTime = $fd['type'] === 'datetime';
        $today = (new \DateTimeImmutable('now', user_tz()))->format('Y-m-d');

        switch ($op) {
            case 'eq':
                if ($fd['type'] === 'tags' || $fd['type'] === 'multiselect') {
                    $where[] = "(',' || REPLACE($col, ', ', ',') || ',') LIKE :$k$esc";
                    if ($this->db->driver !== 'sqlite') {
                        array_pop($where);
                        $where[] = "CONCAT(',', REPLACE($col, ', ', ','), ',') LIKE :$k$esc";
                    }
                    $params[$k] = '%,' . strtr((string) $value, ['%' => '\\%', '_' => '\\_']) . ',%';
                } elseif (is_array($value)) {
                    $this->inClause($col, $value, $k, $where, $params);
                } elseif ($value === '' || $value === null) {
                    $where[] = "($col IS NULL OR $col = '')";
                } else {
                    $where[] = "$col = :$k";
                    $params[$k] = $this->castFilter($fd, $value);
                }
                break;
            case 'in':
                $this->inClause($col, is_array($value) ? $value : explode(',', (string) $value), $k, $where, $params);
                break;
            case 'neq':
                $where[] = "($col IS NULL OR $col <> :$k)";
                $params[$k] = $this->castFilter($fd, $value);
                break;
            case 'contains':
                $where[] = "$col LIKE :$k$esc";
                $params[$k] = DB::like((string) $value);
                break;
            case 'not_contains':
                $where[] = "($col IS NULL OR $col NOT LIKE :$k$esc)";
                $params[$k] = DB::like((string) $value);
                break;
            case 'starts':
                $where[] = "$col LIKE :$k$esc";
                $params[$k] = strtr((string) $value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
                break;
            case 'empty':
                $where[] = "($col IS NULL OR $col = '')";
                break;
            case 'not_empty':
                $where[] = "($col IS NOT NULL AND $col <> '')";
                break;
            case 'gt': case 'gte': case 'lt': case 'lte':
                $sym = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op];
                $where[] = "$col $sym :$k";
                $params[$k] = $this->castFilter($fd, $value);
                break;
            case 'between':
                $where[] = "$col BETWEEN :{$k}a AND :{$k}b";
                $params[$k . 'a'] = $this->castFilter($fd, $value);
                $params[$k . 'b'] = $this->castFilter($fd, $value2, true);
                break;
            case 'is_true':
                $where[] = "$col = 1";
                break;
            case 'is_false':
                $where[] = "($col = 0 OR $col IS NULL)";
                break;
            case 'me':
                $where[] = "$col = :$k";
                $params[$k] = Auth::id();
                break;
            case 'today':
                $this->dateRange($col, $isDateTime, $today, $today, $k, $where, $params);
                break;
            case 'overdue':
                $where[] = "$col < :$k";
                $params[$k] = $isDateTime ? gmdate('Y-m-d H:i:s') : $today;
                break;
            case 'this_week':
                $start = (new \DateTimeImmutable('monday this week', user_tz()))->format('Y-m-d');
                $end = (new \DateTimeImmutable('sunday this week', user_tz()))->format('Y-m-d');
                $this->dateRange($col, $isDateTime, $start, $end, $k, $where, $params);
                break;
            case 'this_month':
                $this->dateRange($col, $isDateTime, (new \DateTimeImmutable('first day of this month', user_tz()))->format('Y-m-d'), (new \DateTimeImmutable('last day of this month', user_tz()))->format('Y-m-d'), $k, $where, $params);
                break;
            case 'last_days':
                $n = max(1, min(3650, (int) $value));
                $this->dateRange($col, $isDateTime, (new \DateTimeImmutable("-$n days", user_tz()))->format('Y-m-d'), $today, $k, $where, $params);
                break;
            case 'next_days':
                $n = max(1, min(3650, (int) $value));
                $this->dateRange($col, $isDateTime, $today, (new \DateTimeImmutable("+$n days", user_tz()))->format('Y-m-d'), $k, $where, $params);
                break;
        }
    }

    private function inClause(string $col, array $values, string $k, array &$where, array &$params): void
    {
        $values = array_slice(array_values(array_filter($values, fn ($v) => $v !== '' && $v !== null && is_scalar($v))), 0, 100);
        if (!$values) {
            return;
        }
        $ph = [];
        foreach ($values as $j => $v) {
            $ph[] = ":{$k}_$j";
            $params["{$k}_$j"] = (string) $v;
        }
        $where[] = "$col IN (" . implode(',', $ph) . ')';
    }

    private function dateRange(string $col, bool $isDateTime, string $from, string $to, string $k, array &$where, array &$params): void
    {
        if ($isDateTime) {
            $f = new \DateTimeImmutable($from . ' 00:00:00', user_tz());
            $t = new \DateTimeImmutable($to . ' 23:59:59', user_tz());
            $from = $f->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $to = $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $where[] = "$col BETWEEN :{$k}from AND :{$k}to";
        $params[$k . 'from'] = $from;
        $params[$k . 'to'] = $to;
    }

    private function castFilter(array $fd, mixed $v, bool $endOfDay = false): mixed
    {
        if (is_array($v)) {
            $v = reset($v);
        }
        return match ($fd['type']) {
            'number', 'relation', 'user', 'pipeline', 'stage', 'tax', 'duration' => (int) $v,
            'decimal', 'money', 'percent' => (float) $v,
            'bool' => (int) (bool) $v,
            'datetime' => (string) ($endOfDay && strlen((string) $v) === 10 ? to_utc($v . ' 23:59:59') : (strlen((string) $v) === 10 ? to_utc($v . ' 00:00:00') : to_utc((string) $v))),
            default => (string) $v,
        };
    }

    private function resolveSort(?string $sort, ?string $dir): array
    {
        $dir = strtolower((string) $dir) === 'asc' ? 'ASC' : (strtolower((string) $dir) === 'desc' ? 'DESC' : null);
        if ($sort && isset($this->def['fields'][$sort]) && $this->def['fields'][$sort]['type'] !== 'hidden') {
            $fd = $this->def['fields'][$sort];
            $col = !empty($fd['custom']) ? $this->db->jsonGet('t.custom', $sort) : 't.' . $this->q($sort);
            return [$col, $dir ?? 'ASC'];
        }
        [$s, $d] = $this->def['default_sort'] ?? ['created_at', 'desc'];
        return ['t.' . $this->q($s), $dir ?? strtoupper($d)];
    }

    // ───────────────────────────── Writing ─────────────────────────────

    public function create(array $input, array $opts = []): array
    {
        if (empty($opts['skip_permission'])) {
            Permissions::authorize($this->module, 'create');
        }
        [$data, $errors] = $this->sanitize($input, null, $opts);
        if ($errors) {
            throw new HttpException(422, '', $errors);
        }
        $uid = Auth::id() ?: null;
        $now = DB::now();
        $data['company_id'] = Auth::companyId();
        $data['created_by'] = $uid;
        $data['updated_by'] = $uid;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        if ($this->hasAssigned() && !array_key_exists('assigned_to', $data)) {
            $data['assigned_to'] = $uid;
        }
        if ($this->hasAssigned() && !empty($data['assigned_to']) && (int) $data['assigned_to'] !== $uid && empty($opts['skip_permission']) && !can($this->module, 'assign')) {
            $data['assigned_to'] = $uid; // cannot assign to others without the Assign permission
        }
        foreach ($this->def['fields'] as $name => $f) {
            if (!array_key_exists($name, $data) && array_key_exists('default', $f) && empty($f['custom'])) {
                $data[$name] = $f['default'];
            }
        }

        return $this->db->transaction(function () use ($data, $opts) {
            EntityHooks::before($this, $data, null);
            $id = $this->db->insert($this->table, $data);
            $record = $this->db->find($this->table, $id);
            EntityHooks::after($this, $record, null);
            $record = $this->db->find($this->table, $id);
            Audit::log('record.created', $this->entity, $id, ['fields' => array_keys($data)]);
            if (empty($opts['silent'])) {
                Events::dispatch('record.created', ['entity' => $this->entity, 'record' => $record, 'old' => null, 'changes' => []]);
            }
            return $this->decorate([$record])[0];
        });
    }

    public function update(int $id, array $input, array $opts = []): array
    {
        $old = $this->db->one("SELECT * FROM {$this->q($this->table)} WHERE id = ? AND company_id = ? AND deleted_at IS NULL", [$id, Auth::companyId()]);
        if (!$old) {
            throw new HttpException(404);
        }
        if (empty($opts['skip_permission']) && !Permissions::can($this->module, 'edit', $old)) {
            throw new HttpException(403);
        }
        [$data, $errors] = $this->sanitize($input, $old, $opts);
        if ($errors) {
            throw new HttpException(422, '', $errors);
        }
        if ($this->hasAssigned() && array_key_exists('assigned_to', $data) && (int) $data['assigned_to'] !== (int) $old['assigned_to']
            && empty($opts['skip_permission']) && !can($this->module, 'assign')) {
            unset($data['assigned_to']);
        }
        $this->guardApproval($data, $old, $opts);
        if (!$data) {
            return $this->decorate([$old])[0];
        }
        $data['updated_by'] = Auth::id() ?: null;
        $data['updated_at'] = DB::now();

        return $this->db->transaction(function () use ($id, $data, $old, $opts) {
            EntityHooks::before($this, $data, $old);
            $this->db->update($this->table, $data, ['id' => $id]);
            $record = $this->db->find($this->table, $id);
            EntityHooks::after($this, $record, $old);
            $record = $this->db->find($this->table, $id);
            $changes = Audit::diff($old, $record, ['updated_at', 'updated_by', 'sort']);
            if ($changes) {
                Audit::log('record.updated', $this->entity, $id, $changes);
                $this->logSystemActivity($id, $changes);
                if (empty($opts['silent'])) {
                    Events::dispatch('record.updated', ['entity' => $this->entity, 'record' => $record, 'old' => $old, 'changes' => $changes]);
                }
            }
            return $this->decorate([$record])[0];
        });
    }

    public function delete(int $id): void
    {
        $old = $this->db->one("SELECT * FROM {$this->q($this->table)} WHERE id = ? AND company_id = ? AND deleted_at IS NULL", [$id, Auth::companyId()]);
        if (!$old) {
            throw new HttpException(404);
        }
        if (!Permissions::can($this->module, 'delete', $old)) {
            throw new HttpException(403);
        }
        $this->db->transaction(function () use ($id, $old) {
            $this->db->update($this->table, ['deleted_at' => DB::now(), 'updated_by' => Auth::id()], ['id' => $id]);
            EntityHooks::deleted($this, $old);
            Audit::log('record.deleted', $this->entity, $id, ['title' => $old[$this->def['title']] ?? null]);
            Events::dispatch('record.deleted', ['entity' => $this->entity, 'record' => $old, 'old' => $old, 'changes' => []]);
        });
    }

    /** Approval-controlled status fields need the Approve permission (requesters may only cancel). */
    private function guardApproval(array &$data, array $old, array $opts): void
    {
        if (!empty($opts['skip_permission'])) {
            return;
        }
        foreach ($this->def['fields'] as $name => $f) {
            if (empty($f['approval']) || !array_key_exists($name, $data) || (string) $data[$name] === (string) $old[$name]) {
                continue;
            }
            $new = (string) $data[$name];
            $isOwnCancel = $new === 'Cancelled' && (int) $old['created_by'] === Auth::id();
            if (!$isOwnCancel && !can($this->module, 'approve')) {
                throw new HttpException(403, 'Changing the status requires approval permission.');
            }
            if (in_array($new, ['Approved', 'Rejected', 'Reimbursed'], true)) {
                $data['approved_by'] = Auth::id();
                $data['approved_at'] = DB::now();
            }
        }
    }

    private function logSystemActivity(int $id, array $changes): void
    {
        $tracked = ['status', 'stage_id', 'assigned_to', 'priority'];
        $lines = [];
        foreach ($tracked as $f) {
            if (isset($changes[$f]) && isset($this->def['fields'][$f])) {
                $fd = $this->def['fields'][$f];
                $from = FieldRenderer::plain($fd, $changes[$f]['from'], $this);
                $to = FieldRenderer::plain($fd, $changes[$f]['to'], $this);
                $lines[] = $fd['label'] . ': ' . ($from !== '' ? $from : '—') . ' → ' . ($to !== '' ? $to : '—');
            }
        }
        if ($lines) {
            ActivityService::log($this->entity, $id, 'system', implode("\n", $lines));
        }
    }

    /**
     * Validates and normalizes input according to field metadata.
     * @return array{0: array, 1: array<string,string>}
     */
    public function sanitize(array $input, ?array $old, array $opts = []): array
    {
        $data = [];
        $errors = [];
        $custom = $old ? (json_decode((string) ($old['custom'] ?? ''), true) ?: []) : [];
        $customTouched = false;
        $isCreate = $old === null;
        $system = !empty($opts['system']);

        foreach ($this->def['fields'] as $name => $f) {
            if (!empty($f['system']) || (($f['readonly'] ?? false) && !$system) || ($f['form'] ?? true) === false) {
                continue;
            }
            if (!empty($f['sensitive']) && !can($this->module, $f['sensitive'])) {
                continue;
            }
            $present = array_key_exists($name, $input);
            if (!$present) {
                if ($isCreate && !empty($f['required']) && !array_key_exists('default', $f)) {
                    $errors[$name] = $f['label'] . ' is required.';
                }
                continue;
            }
            [$value, $err] = $this->normalizeValue($f, $input[$name]);
            if ($err) {
                $errors[$name] = $err;
                continue;
            }
            if (!empty($f['required']) && ($value === null || $value === '')) {
                $errors[$name] = $f['label'] . ' is required.';
                continue;
            }
            if (!empty($f['custom'])) {
                $custom[$name] = $value;
                $customTouched = true;
            } else {
                $data[$name] = $value;
            }
        }
        // Computed full names.
        foreach ($this->def['computed'] as $target => $parts) {
            if (array_intersect($parts, array_keys($data))) {
                $vals = [];
                foreach ($parts as $p) {
                    $vals[] = $data[$p] ?? ($old[$p] ?? '');
                }
                $data[$target] = trim(implode(' ', array_filter($vals, fn ($v) => $v !== null && $v !== '')));
            }
        }
        if ($customTouched || ($isCreate && $custom)) {
            $data['custom'] = json_encode($custom, JSON_UNESCAPED_UNICODE);
        }
        return [$data, $errors];
    }

    /** @return array{0:mixed,1:?string} */
    public function normalizeValue(array $f, mixed $v): array
    {
        $label = $f['label'];
        if (is_string($v)) {
            $v = trim($v);
        }
        $emptyish = $v === null || $v === '' || $v === [];
        switch ($f['type']) {
            case 'text':
            case 'phone':
                if ($emptyish) return [null, null];
                if (!is_scalar($v)) return [null, "$label is invalid."];
                $v = (string) $v;
                if ($f['type'] === 'phone' && !preg_match('/^[0-9+\-().\s\/x]{3,40}$/i', $v)) return [null, "$label is not a valid phone number."];
                return [mb_substr($v, 0, 190), null];
            case 'textarea':
                if ($emptyish) return [null, null];
                return [mb_substr((string) $v, 0, 60000), null];
            case 'email':
                if ($emptyish) return [null, null];
                $v = mb_strtolower((string) $v);
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? [$v, null] : [null, "$label must be a valid email address."];
            case 'url':
                if ($emptyish) return [null, null];
                $v = (string) $v;
                if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
                return filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v) ? [mb_substr($v, 0, 490), null] : [null, "$label must be a valid URL."];
            case 'number':
                if ($emptyish) return [null, null];
                return is_numeric($v) ? [(int) $v, null] : [null, "$label must be a number."];
            case 'decimal':
            case 'money':
                if ($emptyish) return [null, null];
                $v = is_string($v) ? str_replace([',', ' '], '', $v) : $v;
                if (!is_numeric($v)) return [null, "$label must be a number."];
                if (abs((float) $v) > 9999999999999) return [null, "$label is too large."];
                return [round((float) $v, 2), null];
            case 'percent':
                if ($emptyish) return [null, null];
                if (!is_numeric($v)) return [null, "$label must be a number."];
                return [max(0, min(100, (int) round((float) $v))), null];
            case 'duration':
                if ($emptyish) return [null, null];
                $m = self::parseDuration((string) $v);
                return $m === null ? [null, "$label must look like 1:30, 1.5h or 90m."] : [$m, null];
            case 'date':
                if ($emptyish) return [null, null];
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $v, 0, 10));
                return $d ? [$d->format('Y-m-d'), null] : [null, "$label must be a valid date."];
            case 'datetime':
                if ($emptyish) return [null, null];
                $s = (string) $v;
                if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s)) {
                    try {
                        return [(new \DateTimeImmutable($s))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), null];
                    } catch (\Throwable) {
                        return [null, "$label must be a valid date and time."];
                    }
                }
                $u = to_utc($s);
                return $u ? [$u, null] : [null, "$label must be a valid date and time."];
            case 'bool':
                return [in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true) ? 1 : 0, null];
            case 'select':
                if ($emptyish) return [null, null];
                $opts = EntityRegistry::options($f);
                $v = (string) $v;
                if ($opts && !array_key_exists($v, $opts)) {
                    // accept label as input (imports)
                    $k = array_search($v, $opts, true);
                    if ($k === false) return [null, "$label has an invalid value."];
                    $v = (string) $k;
                }
                return [mb_substr($v, 0, 100), null];
            case 'multiselect':
            case 'tags':
                if ($emptyish) return [null, null];
                $list = is_array($v) ? $v : explode(',', (string) $v);
                $list = array_values(array_unique(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 50), $list), fn ($x) => $x !== '')));
                if ($f['type'] === 'multiselect' && ($opts = EntityRegistry::options($f))) {
                    foreach ($list as $x) {
                        if (!array_key_exists($x, $opts)) return [null, "$label has an invalid value."];
                    }
                }
                return [mb_substr(implode(', ', $list), 0, 490), null];
            case 'relation':
                if ($emptyish || (string) $v === '0') return [null, null];
                if (!ctype_digit((string) $v)) return [null, "$label is invalid."];
                $target = EntityRegistry::all()[$f['relation']] ?? null;
                if (!$target) return [null, "$label is invalid."];
                $ok = $this->db->value('SELECT id FROM ' . $this->q($target['table']) . ' WHERE id = ? AND company_id = ? AND deleted_at IS NULL', [(int) $v, Auth::companyId()]);
                return $ok ? [(int) $v, null] : [null, "$label refers to a record that does not exist."];
            case 'user':
                if ($emptyish || (string) $v === '0') return [null, null];
                if (!ctype_digit((string) $v)) return [null, "$label is invalid."];
                $ok = $this->db->value('SELECT u.id FROM users u JOIN company_user cu ON cu.user_id = u.id WHERE u.id = ? AND cu.company_id = ? AND u.deleted_at IS NULL', [(int) $v, Auth::companyId()]);
                return $ok ? [(int) $v, null] : [null, "$label must be an active team member."];
            case 'currency':
                if ($emptyish) return [null, null];
                $v = strtoupper((string) $v);
                $ok = $this->db->value('SELECT id FROM currencies WHERE code = ? AND is_active = 1', [$v]);
                return $ok ? [$v, null] : [null, "$label is not an enabled currency."];
            case 'tax':
                if ($emptyish || (string) $v === '0') return [null, null];
                $ok = $this->db->value('SELECT id FROM taxes WHERE id = ? AND company_id = ?', [(int) $v, Auth::companyId()]);
                return $ok ? [(int) $v, null] : [null, "$label is invalid."];
            case 'pipeline':
                if ($emptyish) return [null, null];
                $ok = $this->db->value('SELECT id FROM pipelines WHERE id = ? AND company_id = ?', [(int) $v, Auth::companyId()]);
                return $ok ? [(int) $v, null] : [null, "$label is invalid."];
            case 'stage':
                if ($emptyish) return [null, null];
                $ok = $this->db->value('SELECT s.id FROM pipeline_stages s JOIN pipelines p ON p.id = s.pipeline_id WHERE s.id = ? AND p.company_id = ?', [(int) $v, Auth::companyId()]);
                return $ok ? [(int) $v, null] : [null, "$label is invalid."];
            case 'hidden':
                if ($emptyish) return [null, null];
                if ($f['name'] === 'related_entity') {
                    return EntityRegistry::exists((string) $v) ? [(string) $v, null] : [null, 'Invalid related record type.'];
                }
                return ctype_digit((string) $v) ? [(int) $v, null] : [null, 'Invalid related record.'];
        }
        return [is_scalar($v) ? (string) $v : null, null];
    }

    public static function parseDuration(string $s): ?int
    {
        $s = strtolower(trim($s));
        if (preg_match('/^(\d{1,3}):(\d{1,2})$/', $s, $m)) return (int) $m[1] * 60 + (int) $m[2];
        if (preg_match('/^(\d+(?:\.\d+)?)\s*h(?:ours?|rs?)?$/', $s, $m)) return (int) round((float) $m[1] * 60);
        if (preg_match('/^(\d+)\s*m(?:in(?:ute)?s?)?$/', $s, $m)) return (int) $m[1];
        if (preg_match('/^(\d+)h\s*(\d+)m?$/', $s, $m)) return (int) $m[1] * 60 + (int) $m[2];
        if (is_numeric($s) && (float) $s >= 0) return (int) round((float) $s);
        return null;
    }

    // ───────────────────────────── Presentation ─────────────────────────────

    /** Adds _labels (relation/user/stage names) and decodes custom fields. Removes sensitive fields. */
    public function decorate(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $byEntity = [];
        $userIds = [];
        $stageIds = [];
        $pipelineIds = [];
        $taxIds = [];
        $fields = $this->def['fields'];
        foreach ($rows as &$row) {
            $custom = json_decode((string) ($row['custom'] ?? ''), true) ?: [];
            foreach ($fields as $name => $f) {
                if (!empty($f['custom'])) {
                    $row[$name] = $custom[$name] ?? null;
                }
            }
            unset($row['custom']);
            foreach ($fields as $name => $f) {
                $v = $row[$name] ?? null;
                if (!$v) {
                    continue;
                }
                match ($f['type']) {
                    'relation' => $byEntity[$f['relation']][(int) $v] = true,
                    'user' => $userIds[(int) $v] = true,
                    'stage' => $stageIds[(int) $v] = true,
                    'pipeline' => $pipelineIds[(int) $v] = true,
                    'tax' => $taxIds[(int) $v] = true,
                    default => null,
                };
            }
            foreach ($fields as $name => $f) {
                if (!empty($f['sensitive']) && !can($this->module, $f['sensitive'])) {
                    unset($row[$name]);
                }
            }
        }
        unset($row);

        $labels = ['users' => [], 'stages' => [], 'pipelines' => [], 'taxes' => []];
        foreach ($byEntity as $ent => $ids) {
            $labels[$ent] = LookupService::titles($ent, array_keys($ids));
        }
        if ($userIds) {
            $labels['users'] = LookupService::users(array_keys($userIds));
        }
        if ($stageIds) {
            $in = implode(',', array_map('intval', array_keys($stageIds)));
            foreach ($this->db->all("SELECT id, name, color, probability, is_won, is_lost FROM pipeline_stages WHERE id IN ($in)") as $s) {
                $labels['stages'][(int) $s['id']] = $s;
            }
        }
        if ($pipelineIds) {
            $in = implode(',', array_map('intval', array_keys($pipelineIds)));
            foreach ($this->db->all("SELECT id, name FROM pipelines WHERE id IN ($in)") as $s) {
                $labels['pipelines'][(int) $s['id']] = $s['name'];
            }
        }
        if ($taxIds) {
            $in = implode(',', array_map('intval', array_keys($taxIds)));
            foreach ($this->db->all("SELECT id, name, rate FROM taxes WHERE id IN ($in)") as $s) {
                $labels['taxes'][(int) $s['id']] = $s['name'] . ' (' . rtrim(rtrim((string) $s['rate'], '0'), '.') . '%)';
            }
        }
        foreach ($rows as &$row) {
            $row['_labels'] = [];
            foreach ($fields as $name => $f) {
                $v = $row[$name] ?? null;
                if (!$v) {
                    continue;
                }
                $label = match ($f['type']) {
                    'relation' => $labels[$f['relation']][(int) $v] ?? null,
                    'user' => $labels['users'][(int) $v]['name'] ?? null,
                    'stage' => $labels['stages'][(int) $v]['name'] ?? null,
                    'pipeline' => $labels['pipelines'][(int) $v] ?? null,
                    'tax' => $labels['taxes'][(int) $v] ?? null,
                    default => null,
                };
                if ($label !== null) {
                    $row['_labels'][$name] = $label;
                }
                if ($f['type'] === 'stage' && isset($labels['stages'][(int) $v])) {
                    $row['_stage'] = $labels['stages'][(int) $v];
                }
            }
            $row['_title'] = $this->titleOf($row);
        }
        return $rows;
    }

    public function titleOf(array $row): string
    {
        $t = $this->def['title'];
        $v = $row['_labels'][$t] ?? ($row[$t] ?? '');
        if ($v === '' || $v === null) {
            return $this->def['singular'] . ' #' . ($row['id'] ?? '');
        }
        return (string) $v;
    }

    public function q(string $ident): string
    {
        return $this->db->ident($ident);
    }

    public function url(int $id): string
    {
        return url('/r/' . $this->entity . '/' . $id);
    }
}

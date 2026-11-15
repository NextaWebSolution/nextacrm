<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\ErrorHandler;
use App\Core\Events;
use App\Core\HttpClient;
use App\Core\Mailer;
use App\Core\RateLimiter;

/**
 * No-code automation: trigger → conditions → actions.
 * Triggers adapted from Odoo base_automation and Twenty workflows; actions adapted from SuiteCRM AOW.
 * Deliberately no "run code" action (remote-code-execution risk on shared hosting).
 */
final class WorkflowService
{
    public const TRIGGERS = [
        'created' => 'When a record is created',
        'updated' => 'When a record is updated',
        'created_or_updated' => 'When a record is created or updated',
        'field_changed' => 'When a field changes to a value',
        'deleted' => 'When a record is deleted',
        'scheduled' => 'Relative to a date field (checked daily)',
        'manual' => 'Manually from the record page',
        'webhook' => 'Incoming webhook (external system)',
    ];

    public const CONDITION_OPS = ['eq' => 'is', 'neq' => 'is not', 'contains' => 'contains', 'empty' => 'is empty', 'not_empty' => 'is not empty', 'gt' => 'greater than', 'lt' => 'less than', 'changed' => 'was changed'];

    public const ACTIONS = [
        'update_field' => 'Update a field',
        'assign_user' => 'Assign to a user',
        'round_robin' => 'Assign round-robin',
        'create_task' => 'Create a follow-up task',
        'send_email' => 'Send an e-mail',
        'notify' => 'Send an in-app notification',
        'add_note' => 'Add a note to the timeline',
        'webhook' => 'Call a webhook URL',
    ];

    /** Event listener for record events. */
    public static function onEvent(string $event, array $p): void
    {
        if (!str_starts_with($event, 'record.') || empty($p['entity']) || empty($p['record'])) {
            return;
        }
        $kind = substr($event, 7); // created|updated|deleted
        $types = match ($kind) {
            'created' => ['created', 'created_or_updated', 'field_changed'],
            'updated' => ['updated', 'created_or_updated', 'field_changed'],
            'deleted' => ['deleted'],
            default => [],
        };
        if (!$types) {
            return;
        }
        $in = "'" . implode("','", $types) . "'";
        $flows = App::db()->all("SELECT * FROM workflows WHERE company_id = ? AND entity = ? AND is_active = 1 AND trigger_type IN ($in) ORDER BY id", [(int) $p['record']['company_id'], $p['entity']]);
        foreach ($flows as $wf) {
            $cfg = json_decode((string) $wf['trigger_config'], true) ?: [];
            if ($wf['trigger_type'] === 'field_changed') {
                $field = (string) ($cfg['field'] ?? '');
                $changes = $p['changes'] ?? [];
                $newVal = $p['record'][$field] ?? null;
                $changed = $kind === 'created' ? $newVal !== null && $newVal !== '' : isset($changes[$field]);
                if (!$changed) {
                    continue;
                }
                if (isset($cfg['to']) && $cfg['to'] !== '' && (string) $newVal !== (string) $cfg['to']) {
                    continue;
                }
            }
            self::execute($wf, $p['entity'], $p['record'], $p['changes'] ?? []);
        }
    }

    /** Workflows currently executing (id:record) — stops loops such as "on update → update field". */
    private static array $running = [];
    private const MAX_DEPTH = 3;

    public static function execute(array $wf, string $entity, array $record, array $changes = [], bool $force = false): array
    {
        $key = $wf['id'] . ':' . ($record['id'] ?? 0);
        if (isset(self::$running[$key]) || count(self::$running) >= self::MAX_DEPTH) {
            return ['status' => 'skipped', 'log' => ['Skipped to prevent a workflow loop']];
        }
        self::$running[$key] = true;
        try {
            return self::executeInner($wf, $entity, $record, $changes, $force);
        } finally {
            unset(self::$running[$key]);
        }
    }

    private static function executeInner(array $wf, string $entity, array $record, array $changes, bool $force): array
    {
        $log = [];
        $status = 'success';
        try {
            if (!$force && !self::conditionsMatch(json_decode((string) $wf['conditions'], true) ?: [], $record, $changes)) {
                return ['status' => 'skipped', 'log' => ['Conditions not met']];
            }
            foreach (json_decode((string) $wf['actions'], true) ?: [] as $i => $action) {
                try {
                    $log[] = self::runAction($wf, $entity, $record, (array) $action);
                    if (in_array($action['type'] ?? '', ['update_field', 'assign_user', 'round_robin'], true)) {
                        $record = App::db()->find(EntityRegistry::all()[$entity]['table'], (int) $record['id']) ?? $record;
                    }
                } catch (\Throwable $e) {
                    $status = 'partial';
                    $log[] = 'Action ' . ($i + 1) . ' failed: ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $log[] = $e->getMessage();
            ErrorHandler::report($e);
        }
        $db = App::db();
        $db->insert('workflow_runs', ['workflow_id' => $wf['id'], 'entity' => $entity, 'record_id' => $record['id'] ?? null, 'status' => $status, 'log' => json_encode($log, JSON_UNESCAPED_UNICODE), 'created_at' => DB::now()]);
        $db->query('UPDATE workflows SET run_count = run_count + 1, last_run_at = ? WHERE id = ?', [DB::now(), $wf['id']]);
        return ['status' => $status, 'log' => $log];
    }

    public static function conditionsMatch(array $conditions, array $record, array $changes = []): bool
    {
        foreach ($conditions as $c) {
            $field = (string) ($c['field'] ?? '');
            $v = $record[$field] ?? null;
            $target = (string) ($c['value'] ?? '');
            $ok = match ($c['op'] ?? 'eq') {
                'eq' => (string) $v === $target,
                'neq' => (string) $v !== $target,
                'contains' => $v !== null && stripos((string) $v, $target) !== false,
                'empty' => $v === null || $v === '',
                'not_empty' => $v !== null && $v !== '',
                'gt' => is_numeric($v) && (float) $v > (float) $target,
                'lt' => is_numeric($v) && (float) $v < (float) $target,
                'changed' => isset($changes[$field]),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    private static function runAction(array $wf, string $entity, array $record, array $a): string
    {
        $svc = RecordService::for($entity);
        $id = (int) $record['id'];
        $type = (string) ($a['type'] ?? '');
        switch ($type) {
            case 'update_field':
                $field = (string) ($a['field'] ?? '');
                if (!isset($svc->def['fields'][$field])) {
                    return 'Skipped: unknown field';
                }
                $svc->update($id, [$field => self::render((string) ($a['value'] ?? ''), $entity, $record)], ['skip_permission' => true, 'system' => false]);
                return "Updated $field";
            case 'assign_user':
                $svc->update($id, ['assigned_to' => (int) ($a['user_id'] ?? 0)], ['skip_permission' => true]);
                return 'Assigned to user #' . (int) ($a['user_id'] ?? 0);
            case 'round_robin':
                $users = array_values(array_filter(array_map('intval', (array) ($a['user_ids'] ?? []))));
                if (!$users) {
                    return 'Skipped: no users';
                }
                $key = 'wf.rr.' . $wf['id'];
                $idx = (int) \App\Core\Settings::get($key, 0);
                $uid = $users[$idx % count($users)];
                \App\Core\Settings::set($key, $idx + 1, (int) $wf['company_id']);
                $svc->update($id, ['assigned_to' => $uid], ['skip_permission' => true]);
                return "Round-robin assigned to user #$uid";
            case 'create_task':
                $assignee = self::resolveUser((string) ($a['assign_to'] ?? 'owner'), $record);
                $days = (int) ($a['due_in_days'] ?? 1);
                $task = RecordService::for('tasks')->create([
                    'title' => self::render((string) ($a['title'] ?? 'Follow up'), $entity, $record),
                    'description' => self::render((string) ($a['description'] ?? ''), $entity, $record),
                    'priority' => in_array($a['priority'] ?? '', ['Low', 'Medium', 'High', 'Urgent'], true) ? $a['priority'] : 'Medium',
                    'due_date' => date('Y-m-d', strtotime("+$days days")),
                    'assigned_to' => $assignee,
                    'related_entity' => $entity,
                    'related_id' => $id,
                    'project_id' => $entity === 'projects' ? $id : ($record['project_id'] ?? null),
                ], ['skip_permission' => true]);
                return 'Created task #' . $task['id'];
            case 'send_email':
                $to = match ($a['to'] ?? 'owner') {
                    'owner' => App::db()->value('SELECT email FROM users WHERE id = ?', [(int) ($record['assigned_to'] ?? 0)]),
                    'creator' => App::db()->value('SELECT email FROM users WHERE id = ?', [(int) ($record['created_by'] ?? 0)]),
                    'record' => $record['email'] ?? $record['requester_email'] ?? null,
                    default => $a['email'] ?? null,
                };
                if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    return 'Skipped: no valid recipient';
                }
                if (!RateLimiter::hit('wf-mail:' . $wf['company_id'], 500, 3600)) {
                    return 'Skipped: hourly e-mail limit reached';
                }
                Mailer::queue((string) $to, self::render((string) ($a['subject'] ?? ''), $entity, $record), nl2br_safe(self::render((string) ($a['body'] ?? ''), $entity, $record)));
                if (($a['to'] ?? '') === 'record') {
                    ActivityService::log($entity, $id, 'email', 'Automated e-mail sent to ' . $to, self::render((string) ($a['subject'] ?? ''), $entity, $record));
                }
                return "E-mail queued to $to";
            case 'notify':
                $uid = self::resolveUser((string) ($a['to'] ?? 'owner'), $record);
                if ($uid) {
                    NotificationService::notify($uid, 'workflow', self::render((string) ($a['title'] ?? $wf['name']), $entity, $record), self::render((string) ($a['body'] ?? ''), $entity, $record), '/r/' . $entity . '/' . $id, (int) $wf['company_id'], !empty($a['email']));
                    return "Notified user #$uid";
                }
                return 'Skipped: no user';
            case 'add_note':
                ActivityService::log($entity, $id, 'note', self::render((string) ($a['body'] ?? ''), $entity, $record), 'Automation: ' . $wf['name']);
                return 'Note added';
            case 'webhook':
                $url = (string) ($a['url'] ?? '');
                $payload = json_encode(['workflow' => $wf['name'], 'entity' => $entity, 'record' => self::publicRecord($entity, $record)], JSON_UNESCAPED_UNICODE);
                $res = (new HttpClient())->request('POST', $url, ['Content-Type: application/json'], (string) $payload);
                return $res['error'] ? 'Webhook failed: ' . $res['error'] : 'Webhook responded ' . $res['status'];
        }
        return 'Skipped: unknown action';
    }

    private static function resolveUser(string $spec, array $record): ?int
    {
        if ($spec === 'owner') {
            return (int) ($record['assigned_to'] ?? 0) ?: ((int) ($record['created_by'] ?? 0) ?: null);
        }
        if ($spec === 'creator') {
            return (int) ($record['created_by'] ?? 0) ?: null;
        }
        if (str_starts_with($spec, 'user:')) {
            return (int) substr($spec, 5) ?: null;
        }
        return null;
    }

    /** Replace {{field}} placeholders with plain values; {{today}}, {{today+N}}, {{record_url}}. */
    public static function render(string $tpl, string $entity, array $record): string
    {
        $def = EntityRegistry::get($entity);
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_+\-.]+)\s*\}\}/i', function ($m) use ($def, $record, $entity) {
            $k = $m[1];
            if ($k === 'today') return date('Y-m-d');
            if (preg_match('/^today([+-]\d+)$/', $k, $mm)) return date('Y-m-d', strtotime($mm[1] . ' days'));
            if ($k === 'record_url') return full_url('/r/' . $entity . '/' . ($record['id'] ?? ''));
            if ($k === 'user.name') return (string) (Auth::user()['name'] ?? '');
            if (isset($def['fields'][$k])) return FieldRenderer::plain($def['fields'][$k], $record[$k] ?? null);
            if ($k === 'id') return (string) ($record['id'] ?? '');
            return '';
        }, $tpl);
    }

    public static function publicRecord(string $entity, array $record): array
    {
        $def = EntityRegistry::get($entity);
        $out = ['id' => (int) $record['id']];
        foreach ($def['fields'] as $name => $f) {
            if (!empty($f['sensitive']) || !empty($f['custom'])) {
                continue;
            }
            if (array_key_exists($name, $record)) {
                $out[$name] = $record[$name];
            }
        }
        $custom = json_decode((string) ($record['custom'] ?? ''), true);
        if (is_array($custom) && $custom) {
            $out['custom'] = $custom;
        }
        return $out;
    }

    /** Cron: date-relative workflows, once per record per workflow per day. */
    public static function runScheduled(): int
    {
        $db = App::db();
        $n = 0;
        foreach ($db->all("SELECT * FROM workflows WHERE is_active = 1 AND trigger_type = 'scheduled'") as $wf) {
            if (!EntityRegistry::exists($wf['entity'])) {
                continue;
            }
            $cfg = json_decode((string) $wf['trigger_config'], true) ?: [];
            $def = EntityRegistry::all()[$wf['entity']];
            $field = (string) ($cfg['date_field'] ?? '');
            if (!isset($def['fields'][$field]) || !in_array($def['fields'][$field]['type'], ['date', 'datetime'], true)) {
                continue;
            }
            $offset = (int) ($cfg['offset_days'] ?? 0); // negative = before the date
            $target = date('Y-m-d', strtotime((-$offset) . ' days'));
            $col = $db->ident($field);
            $table = $db->ident($def['table']);
            $dateExpr = $def['fields'][$field]['type'] === 'datetime' ? "substr($col, 1, 10)" : $col;
            $rows = $db->all("SELECT * FROM $table WHERE company_id = ? AND deleted_at IS NULL AND $dateExpr = ? LIMIT 500", [$wf['company_id'], $target]);
            foreach ($rows as $row) {
                $already = $db->value('SELECT id FROM workflow_runs WHERE workflow_id = ? AND record_id = ? AND created_at >= ?', [$wf['id'], $row['id'], gmdate('Y-m-d 00:00:00')]);
                if ($already) {
                    continue;
                }
                Auth::actingAs((int) $wf['created_by'], (int) $wf['company_id'], function () use ($wf, $row, &$n) {
                    self::execute($wf, $wf['entity'], $row);
                    $n++;
                });
            }
        }
        return $n;
    }
}

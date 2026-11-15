<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Mailer;

final class NotificationService
{
    public static function notify(int $userId, string $type, string $title, string $body = '', ?string $url = null, ?int $companyId = null, bool $email = true): void
    {
        if ($userId <= 0) {
            return;
        }
        $db = App::db();
        $db->insert('notifications', [
            'company_id' => $companyId ?? Auth::companyId(),
            'user_id' => $userId,
            'type' => mb_substr($type, 0, 50),
            'title' => mb_substr($title, 0, 190),
            'body' => mb_substr($body, 0, 2000),
            'url' => $url,
            'created_at' => DB::now(),
        ]);
        if ($email) {
            $u = $db->one('SELECT email, name, preferences, status FROM users WHERE id = ?', [$userId]);
            $prefs = json_decode((string) ($u['preferences'] ?? ''), true) ?: [];
            if ($u && $u['status'] === 'active' && ($prefs['email_notifications'] ?? true)) {
                $link = $url ? '<p><a href="' . e(full_url($url)) . '" style="color:#4f46e5">Open in ' . e((string) setting('app.name', 'NextaCRM')) . '</a></p>' : '';
                Mailer::queue($u['email'], $title, '<p>Hi ' . e($u['name']) . ',</p><p>' . nl2br_safe($body ?: $title) . '</p>' . $link);
            }
        }
    }

    /** Event listener: notify users when records are assigned to them. */
    public static function onEvent(string $event, array $p): void
    {
        if (!in_array($event, ['record.created', 'record.updated'], true) || empty($p['record']['assigned_to'])) {
            return;
        }
        $rec = $p['record'];
        $old = $p['old'] ?? null;
        $assignee = (int) $rec['assigned_to'];
        if ($assignee === Auth::id() || ($old && (int) $old['assigned_to'] === $assignee)) {
            return;
        }
        if (!EntityRegistry::exists($p['entity'])) {
            return;
        }
        $def = EntityRegistry::all()[$p['entity']];
        $svc = RecordService::for($p['entity']);
        $title = $svc->titleOf($rec);
        $who = Auth::user()['name'] ?? 'Someone';
        self::notify($assignee, 'assigned', $def['singular'] . ' assigned to you: ' . $title, "$who assigned you the " . strtolower($def['singular']) . " \"$title\".", '/r/' . $p['entity'] . '/' . $rec['id']);
    }

    public static function unreadCount(): int
    {
        return (int) App::db()->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND company_id = ? AND read_at IS NULL', [Auth::id(), Auth::companyId()]);
    }

    public static function latest(int $limit = 20): array
    {
        return App::db()->all('SELECT * FROM notifications WHERE user_id = ? AND company_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [Auth::id(), Auth::companyId()]);
    }

    /** Cron: due-today task reminders, event reminders, contract renewals, SLA risk. */
    public static function runReminders(): int
    {
        $db = App::db();
        $n = 0;
        $today = date('Y-m-d');
        $lastDaily = (string) \App\Core\Settings::get('cron.last_daily', '');
        if ($lastDaily !== $today) {
            foreach ($db->all("SELECT id, title, assigned_to, company_id FROM tasks WHERE deleted_at IS NULL AND status <> 'Done' AND due_date = ? AND assigned_to IS NOT NULL", [$today]) as $t) {
                self::notify((int) $t['assigned_to'], 'task.due', 'Task due today: ' . $t['title'], '', '/r/tasks/' . $t['id'], (int) $t['company_id']);
                $n++;
            }
            foreach ($db->all("SELECT id, title, end_date, assigned_to, company_id, renewal_reminder_days FROM contracts WHERE deleted_at IS NULL AND status = 'Active' AND end_date IS NOT NULL") as $c) {
                $days = (int) ((strtotime($c['end_date']) - strtotime($today)) / 86400);
                if ($days === (int) $c['renewal_reminder_days'] && $c['assigned_to']) {
                    self::notify((int) $c['assigned_to'], 'contract.renewal', 'Contract renewal in ' . $days . ' days: ' . $c['title'], 'Ends on ' . $c['end_date'], '/r/contracts/' . $c['id'], (int) $c['company_id']);
                    $n++;
                }
            }
            foreach ($db->all("SELECT id, name, assigned_to, company_id FROM leads WHERE deleted_at IS NULL AND follow_up_date = ? AND status NOT IN ('Converted','Lost') AND assigned_to IS NOT NULL", [$today]) as $l) {
                self::notify((int) $l['assigned_to'], 'lead.follow_up', 'Follow up today: ' . $l['name'], '', '/r/leads/' . $l['id'], (int) $l['company_id']);
                $n++;
            }
            $db->query("UPDATE contracts SET status = 'Expired' WHERE deleted_at IS NULL AND status = 'Active' AND end_date < ?", [$today]);
            \App\Core\Settings::set('cron.last_daily', $today);
        }
        // Event reminders
        foreach ($db->all('SELECT * FROM events WHERE deleted_at IS NULL AND reminder_minutes IS NOT NULL AND reminder_sent = 0 AND starts_at > ? AND starts_at <= ?', [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 86400 + 60)]) as $ev) {
            if (strtotime($ev['starts_at'] . ' UTC') - (int) $ev['reminder_minutes'] * 60 <= time()) {
                $users = array_unique(array_filter(array_merge([(int) $ev['assigned_to']], array_map('intval', explode(',', (string) $ev['attendees'])))));
                foreach ($users as $u) {
                    self::notify($u, 'event.reminder', 'Reminder: ' . $ev['title'], 'Starts at ' . gmdate('Y-m-d H:i', strtotime($ev['starts_at'] . ' UTC')) . ' UTC' . ($ev['meeting_url'] ? "\n" . $ev['meeting_url'] : ''), '/r/events/' . $ev['id'], (int) $ev['company_id']);
                }
                $db->update('events', ['reminder_sent' => 1], ['id' => $ev['id']]);
                $n++;
            }
        }
        // SLA: mark at risk / breached
        $now = gmdate('Y-m-d H:i:s');
        $db->query("UPDATE tickets SET sla_status = 'Breached' WHERE deleted_at IS NULL AND status NOT IN ('Resolved','Closed') AND resolution_due IS NOT NULL AND resolution_due < ? AND (sla_status IS NULL OR sla_status <> 'Breached')", [$now]);
        $db->query("UPDATE tickets SET sla_status = 'Breached' WHERE deleted_at IS NULL AND first_responded_at IS NULL AND status NOT IN ('Resolved','Closed') AND first_response_due IS NOT NULL AND first_response_due < ? AND (sla_status IS NULL OR sla_status = 'On Track' OR sla_status = 'At Risk')", [$now]);
        $db->query("UPDATE tickets SET sla_status = 'At Risk' WHERE deleted_at IS NULL AND status NOT IN ('Resolved','Closed') AND sla_status = 'On Track' AND resolution_due IS NOT NULL AND resolution_due < ?", [gmdate('Y-m-d H:i:s', time() + 7200)]);
        return $n;
    }
}

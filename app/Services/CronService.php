<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\DB;
use App\Core\ErrorHandler;
use App\Core\Mailer;
use App\Core\Settings;

/** Scheduler entry point: `php cron.php` every 5 minutes (or the secret cron URL). */
final class CronService
{
    public static function run(): array
    {
        $lock = NX_ROOT . '/storage/cron.lock';
        $fh = fopen($lock, 'c');
        if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'Another cron run is in progress'];
        }
        @set_time_limit(280);
        $report = [];
        $jobs = [
            'emails' => fn () => Mailer::processQueue(100),
            'webhooks' => fn () => WebhookService::processQueue(100),
            'overdue' => fn () => self::perCompany(fn () => SalesService::markOverdue()),
            'recurring_invoices' => fn () => self::perCompany(fn () => SalesService::generateRecurring()),
            'reminders' => fn () => NotificationService::runReminders(),
            'scheduled_workflows' => fn () => self::daily('workflows', fn () => WorkflowService::runScheduled()),
            'cleanup' => fn () => self::daily('cleanup', fn () => self::cleanup()),
            'purge_deleted' => fn () => self::daily('purge', fn () => self::purgeDeleted()),
        ];
        foreach ($jobs as $name => $job) {
            try {
                $report[$name] = $job();
            } catch (\Throwable $e) {
                ErrorHandler::report($e);
                $report[$name] = 'error: ' . $e->getMessage();
            }
        }
        Settings::set('cron.last_run', time());
        flock($fh, LOCK_UN);
        fclose($fh);
        return $report;
    }

    /** Run a callback once for each active company, acting as its first administrator. */
    private static function perCompany(callable $fn): int
    {
        $n = 0;
        foreach (App::db()->all('SELECT id FROM companies WHERE is_active = 1') as $c) {
            $n += (int) \App\Core\Auth::actingAs(0, (int) $c['id'], $fn);
        }
        return $n;
    }

    private static function daily(string $key, callable $fn): mixed
    {
        $k = 'cron.daily.' . $key;
        if (Settings::get($k) === date('Y-m-d')) {
            return 'already ran today';
        }
        $r = $fn();
        Settings::set($k, date('Y-m-d'));
        return $r;
    }

    /**
     * Permanently removes records that were deleted more than `data.purge_days` days ago (default 90),
     * together with their line items, timeline entries and attached files (including the stored objects).
     */
    public static function purgeDeleted(): string
    {
        $days = max(7, (int) setting('data.purge_days', 90));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $db = App::db();
        $total = 0;
        foreach (\App\Services\EntityRegistry::all() as $entity => $def) {
            $table = $db->ident($def['table']);
            $ids = array_map('intval', $db->column("SELECT id FROM $table WHERE deleted_at IS NOT NULL AND deleted_at < ? LIMIT 500", [$cutoff]));
            if (!$ids) {
                continue;
            }
            $in = implode(',', $ids);
            if (in_array($entity, ['quotes', 'invoices'], true)) {
                $db->query("DELETE FROM line_items WHERE parent_type = ? AND parent_id IN ($in)", [$entity === 'quotes' ? 'quote' : 'invoice']);
            }
            $db->query("DELETE FROM activities WHERE entity = ? AND record_id IN ($in)", [$entity]);
            foreach ($db->all("SELECT id, driver, path FROM files WHERE entity = ? AND record_id IN ($in)", [$entity]) as $f) {
                try {
                    \App\Core\Storage\StorageManager::disk($f['driver'])->delete($f['path']);
                } catch (\Throwable $e) {
                    ErrorHandler::log('Purge: could not delete stored file #' . $f['id'] . ': ' . $e->getMessage(), 'storage');
                }
            }
            $db->query("DELETE FROM files WHERE entity = ? AND record_id IN ($in)", [$entity]);
            $db->query("DELETE FROM $table WHERE id IN ($in)");
            $total += count($ids);
        }
        // Files deleted on their own (soft-deleted file rows) past the cutoff.
        $db->query('DELETE FROM files WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$cutoff]);
        return "$total record(s) purged";
    }

    private static function cleanup(): string
    {
        $db = App::db();
        $db->query('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $db->query('DELETE FROM rate_limits WHERE reset_at < ?', [time() - 86400]);
        $db->query("DELETE FROM email_queue WHERE status = 'sent' AND sent_at < ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $db->query("DELETE FROM webhook_deliveries WHERE status IN ('success','cancelled') AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $db->query('DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR last_seen_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400), gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
        $db->query('DELETE FROM password_resets WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        $db->query('DELETE FROM workflow_runs WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
        foreach (glob(NX_ROOT . '/storage/tmp/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400 && basename($f) !== '.htaccess') {
                @unlink($f);
            }
        }
        foreach (glob(NX_ROOT . '/storage/logs/*.log') ?: [] as $f) {
            if (filemtime($f) < time() - 60 * 86400) {
                @unlink($f);
            }
        }
        BackupService::prune(10);
        return 'ok';
    }
}

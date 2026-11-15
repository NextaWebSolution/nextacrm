<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Synchronous event bus. Record events fan out to workflows, webhooks and notifications.
 */
final class Events
{
    private static array $listeners = [];
    private static int $depth = 0;

    public static function listen(string $event, callable $fn): void
    {
        self::$listeners[$event][] = $fn;
    }

    public static function dispatch(string $event, array $payload = []): void
    {
        // Recursion guard: workflows that update records may trigger further events.
        if (self::$depth >= 3) {
            return;
        }
        self::$depth++;
        try {
            foreach (array_merge(self::$listeners[$event] ?? [], self::$listeners['*'] ?? []) as $fn) {
                try {
                    $fn($event, $payload);
                } catch (\Throwable $e) {
                    ErrorHandler::report($e);
                }
            }
        } finally {
            self::$depth--;
        }
    }

    public static function depth(): int
    {
        return self::$depth;
    }

    public static function boot(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;
        self::listen('*', [\App\Services\WorkflowService::class, 'onEvent']);
        self::listen('*', [\App\Services\WebhookService::class, 'onEvent']);
        self::listen('*', [\App\Services\NotificationService::class, 'onEvent']);
    }
}

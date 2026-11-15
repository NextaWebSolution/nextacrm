<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Auth;

final class CompanyService
{
    private static ?array $cache = null;

    /** The active company row (cached per request). */
    public static function current(): array
    {
        if (self::$cache !== null && (int) self::$cache['id'] === Auth::companyId()) {
            return self::$cache;
        }
        $row = Auth::companyId() ? App::db()->find('companies', Auth::companyId()) : null;
        return self::$cache = $row ?: ['id' => 0, 'name' => (string) setting('app.name', 'NextaCRM'), 'currency' => 'INR', 'brand_color' => '#4f46e5', 'payment_terms_days' => 15];
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function currentCurrency(): string
    {
        return (string) (self::current()['currency'] ?? 'INR');
    }

    /** Companies the current user can switch to. */
    public static function available(): array
    {
        $ids = Auth::user()['company_ids'] ?? [];
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        return App::db()->all("SELECT id, name, brand_color FROM companies WHERE id IN ($in) AND is_active = 1 ORDER BY name");
    }

    /** Atomically reserve the next document number for the active company. */
    public static function nextNumber(string $type): string
    {
        $map = ['invoice' => ['invoice_prefix', 'invoice_next'], 'quote' => ['quote_prefix', 'quote_next'], 'ticket' => ['ticket_prefix', 'ticket_next']];
        [$prefixCol, $nextCol] = $map[$type];
        $db = App::db();
        $cid = Auth::companyId();
        return $db->transaction(function () use ($db, $cid, $prefixCol, $nextCol, $type) {
            $lock = $db->driver === 'mysql' ? ' FOR UPDATE' : '';
            $row = $db->one("SELECT $prefixCol AS p, $nextCol AS n FROM companies WHERE id = ?$lock", [$cid]);
            $n = (int) ($row['n'] ?? 1);
            $table = $type === 'invoice' ? 'invoices' : ($type === 'quote' ? 'quotes' : 'tickets');
            // Skip numbers already used (e.g. after manual edits or imports).
            do {
                $number = ($row['p'] ?? '') . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                $exists = $db->value("SELECT id FROM $table WHERE company_id = ? AND number = ?", [$cid, $number]);
                $n++;
            } while ($exists);
            $db->update('companies', [$nextCol => $n], ['id' => $cid]);
            self::$cache = null;
            return $number;
        });
    }

    /** Convert an amount between currencies using stored rates (rates are relative to a common base). */
    public static function convert(float $amount, string $from, string $to): float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return $amount;
        }
        $rates = [];
        foreach (LookupService::currencies() as $c) {
            $rates[$c['code']] = (float) $c['rate'];
        }
        $rf = $rates[strtoupper($from)] ?? 0;
        $rt = $rates[strtoupper($to)] ?? 0;
        if ($rf <= 0 || $rt <= 0) {
            return $amount;
        }
        return round($amount / $rf * $rt, 2);
    }
}

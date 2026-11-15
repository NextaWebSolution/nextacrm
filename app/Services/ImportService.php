<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\HttpException;
use App\Core\Permissions;

/** CSV import with header mapping, validation dry-run and per-row error report. */
final class ImportService
{
    public const MAX_ROWS = 5000;

    /** Parse an uploaded CSV into [headers, rows]. */
    public static function parse(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new HttpException(422, 'Could not read the file.');
        }
        $first = (string) fgets($fh);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        rewind($fh);
        $headers = fgetcsv($fh, 0, $delim, '"', '') ?: [];
        $headers = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? ''), $headers);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($r === [null] || !array_filter($r, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $rows[] = $r;
            if (count($rows) > self::MAX_ROWS) {
                throw new HttpException(422, 'Imports are limited to ' . self::MAX_ROWS . ' rows per file.');
            }
        }
        fclose($fh);
        if (!$headers || !$rows) {
            throw new HttpException(422, 'The file has no data rows.');
        }
        return [$headers, $rows];
    }

    /** Suggest a field for each CSV header (by name/label similarity). */
    public static function suggestMapping(string $entity, array $headers): array
    {
        $def = EntityRegistry::get($entity);
        $map = [];
        foreach ($headers as $i => $h) {
            $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $h) ?? '');
            $map[$i] = '';
            foreach ($def['fields'] as $name => $f) {
                if (!self::importable($f)) {
                    continue;
                }
                if ($norm === strtolower(str_replace('_', '', $name)) || $norm === strtolower(preg_replace('/[^a-z0-9]/i', '', $f['label']) ?? '')) {
                    $map[$i] = $name;
                    break;
                }
            }
        }
        return $map;
    }

    public static function importable(array $f): bool
    {
        return empty($f['system']) && empty($f['readonly']) && ($f['form'] ?? true) !== false && $f['type'] !== 'hidden';
    }

    /**
     * @param array $mapping column index => field name
     * @return array{created:int, errors:array}
     */
    public static function run(string $entity, array $rows, array $mapping, bool $dryRun): array
    {
        $svc = RecordService::for($entity);
        Permissions::authorize($svc->module, 'import');
        Permissions::authorize($svc->module, 'create');
        $created = 0;
        $errors = [];
        $db = App::db();
        foreach ($rows as $n => $r) {
            $input = [];
            foreach ($mapping as $col => $field) {
                if ($field === '' || !isset($svc->def['fields'][$field]) || !self::importable($svc->def['fields'][$field])) {
                    continue;
                }
                $value = $r[(int) $col] ?? null;
                $f = $svc->def['fields'][$field];
                // Resolve relation/user by title or e-mail when a name is given instead of an ID.
                if ($value !== null && $value !== '' && !ctype_digit((string) $value)) {
                    if ($f['type'] === 'relation') {
                        $tdef = EntityRegistry::all()[$f['relation']];
                        $value = $db->value('SELECT id FROM ' . $db->ident($tdef['table']) . ' WHERE company_id = ? AND deleted_at IS NULL AND ' . $db->ident($tdef['title']) . ' = ? LIMIT 1', [\App\Core\Auth::companyId(), trim((string) $value)]) ?: $value;
                    } elseif ($f['type'] === 'user') {
                        $value = $db->value('SELECT id FROM users WHERE (email = ? OR name = ?) AND deleted_at IS NULL LIMIT 1', [mb_strtolower(trim((string) $value)), trim((string) $value)]) ?: $value;
                    }
                }
                $input[$field] = $value;
            }
            [, $errs] = $svc->sanitize($input, null);
            if ($errs) {
                $errors[] = ['row' => $n + 2, 'errors' => array_values($errs)];
                continue;
            }
            if (!$dryRun) {
                try {
                    $svc->create($input, ['silent' => true]);
                    $created++;
                } catch (HttpException $e) {
                    $errors[] = ['row' => $n + 2, 'errors' => $e->errors ? array_values($e->errors) : [$e->getMessage()]];
                }
            } else {
                $created++;
            }
            if (count($errors) > 200) {
                break;
            }
        }
        if (!$dryRun) {
            Audit::log('records.imported', $entity, null, ['created' => $created, 'errors' => count($errors)]);
        }
        return ['created' => $created, 'errors' => $errors];
    }
}

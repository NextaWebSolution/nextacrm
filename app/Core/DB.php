<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. All values are bound; identifiers must pass ident().
 * Supports MySQL/MariaDB (production) and SQLite (automated tests).
 */
final class DB
{
    public PDO $pdo;
    public string $driver;
    private int $txDepth = 0;
    public int $queryCount = 0;

    public function __construct(array $cfg)
    {
        $this->driver = $cfg['driver'] ?? 'mysql';
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($this->driver === 'sqlite') {
            $this->pdo = new PDO('sqlite:' . $cfg['database'], null, null, $opts);
            $this->pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
            return;
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'] ?? 'localhost', (int) ($cfg['port'] ?? 3306), $cfg['database'] ?? '');
        if (!empty($cfg['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $cfg['socket'], $cfg['database'] ?? '');
        }
        $opts[PDO::ATTR_TIMEOUT] = 5;
        $this->pdo = new PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', $opts);
        $this->pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $this->queryCount++;
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($key, is_bool($v) ? (int) $v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $r = $this->query($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $r = $this->query($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public function column(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->ident($table) . ' (' . implode(',', array_map([$this, 'ident'], $cols)) . ') VALUES (' . implode(',', array_map(fn ($c) => ':' . $c, $cols)) . ')';
        $this->query($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array $where column => value (AND-ed equality) */
    public function update(string $table, array $data, array $where): int
    {
        if (!$data) {
            return 0;
        }
        $params = [];
        $sets = [];
        foreach ($data as $c => $v) {
            $sets[] = $this->ident($c) . ' = :s_' . $c;
            $params['s_' . $c] = $v;
        }
        [$w, $wp] = $this->where($where);
        return $this->query('UPDATE ' . $this->ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $w, $params + $wp)->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        [$w, $wp] = $this->where($where);
        return $this->query('DELETE FROM ' . $this->ident($table) . ' WHERE ' . $w, $wp)->rowCount();
    }

    public function find(string $table, int $id): ?array
    {
        return $this->one('SELECT * FROM ' . $this->ident($table) . ' WHERE id = :id', ['id' => $id]);
    }

    public function firstWhere(string $table, array $where): ?array
    {
        [$w, $wp] = $this->where($where);
        return $this->one('SELECT * FROM ' . $this->ident($table) . ' WHERE ' . $w . ' LIMIT 1', $wp);
    }

    private function where(array $where): array
    {
        if (!$where) {
            throw new \InvalidArgumentException('Refusing to run an UPDATE/DELETE without a WHERE clause');
        }
        $parts = [];
        $params = [];
        foreach ($where as $c => $v) {
            if ($v === null) {
                $parts[] = $this->ident($c) . ' IS NULL';
            } else {
                $parts[] = $this->ident($c) . ' = :w_' . $c;
                $params['w_' . $c] = $v;
            }
        }
        return [implode(' AND ', $parts), $params];
    }

    /** Validates and quotes an identifier (optionally alias.column). */
    public function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}(\.[A-Za-z_][A-Za-z0-9_]{0,63})?$/', $name)) {
            throw new \InvalidArgumentException('Invalid identifier');
        }
        $q = $this->driver === 'sqlite' ? '"' : '`';
        return implode('.', array_map(fn ($p) => $q . $p . $q, explode('.', $name)));
    }

    public function transaction(callable $fn): mixed
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $result = $fn($this);
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Escape LIKE wildcards in user input. */
    public static function like(string $s): string
    {
        return '%' . strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }

    public function likeEscape(): string
    {
        // MySQL treats backslash as an escape inside string literals, SQLite does not.
        return $this->driver === 'sqlite' ? " ESCAPE '\\'" : " ESCAPE '\\\\'";
    }

    public function tableExists(string $table): bool
    {
        if ($this->driver === 'sqlite') {
            return (bool) $this->value("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
        }
        return (bool) $this->value('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Portable "date part" expressions used by reports. */
    public function yearMonth(string $col): string
    {
        return $this->driver === 'sqlite' ? "strftime('%Y-%m', $col)" : "DATE_FORMAT($col, '%Y-%m')";
    }

    /** Row-lock suffix for SELECT inside a transaction (SQLite locks the whole database instead). */
    public function forUpdate(): string
    {
        return $this->driver === 'sqlite' ? '' : ' FOR UPDATE';
    }

    public function jsonGet(string $col, string $key): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new \InvalidArgumentException('Invalid JSON key');
        }
        return $this->driver === 'sqlite'
            ? "json_extract($col, '$.$key')"
            : "JSON_UNQUOTE(JSON_EXTRACT($col, '$.$key'))";
    }
}

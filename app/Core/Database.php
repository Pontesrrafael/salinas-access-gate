<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Acesso ao MariaDB/MySQL via PDO com prepared statements reais.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = App::config('db');
            $dsn = $c['socket'] !== ''
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['database'])
                : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']);
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $offset = App::config('app')['db_offset'];
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            self::$pdo->prepare('SET time_zone = ?')->execute([$offset]);
            // IP do cliente disponível para a trigger de auditoria de registros_acesso
            if (PHP_SAPI !== 'cli') {
                self::$pdo->prepare('SET @sag_ip = ?')->execute([Http::clientIp()]);
            }
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * INSERT simples a partir de array associativo (nomes de coluna vêm do código, nunca do usuário).
     */
    public static function insert(string $table, array $data): void
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map([self::class, 'ident'], $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values($data));
    }

    public static function update(string $table, array $data, string $where, array $whereParams): int
    {
        $sets = implode(', ', array_map(fn ($c) => self::ident($c) . ' = ?', array_keys($data)));
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::ident($table), $sets, $where);
        return self::run($sql, [...array_values($data), ...$whereParams])->rowCount();
    }

    /**
     * Executa um bloco em transação; desfaz tudo em caso de exceção.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Placeholders "?, ?, ?" para cláusulas IN. */
    public static function in(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }

    private static function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Identificador SQL inválido: ' . $name);
        }
        return '`' . $name . '`';
    }
}

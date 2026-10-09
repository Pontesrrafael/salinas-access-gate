<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Configurações gerais persistidas na tabela configuracoes. */
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::all('SELECT chave, valor FROM configuracoes') as $r) {
                self::$cache[$r['chave']] = $r['valor'];
            }
        }
        return self::$cache;
    }

    public static function get(string $chave, ?string $default = null): ?string
    {
        return self::all()[$chave] ?? $default;
    }

    public static function bool(string $chave): bool
    {
        return self::get($chave, '0') === '1';
    }

    public static function int(string $chave, int $default): int
    {
        $v = self::get($chave);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function set(string $chave, ?string $valor, ?string $usuarioId): void
    {
        Database::run(
            'INSERT INTO configuracoes (chave, valor, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_by = VALUES(updated_by)',
            [$chave, $valor, $usuarioId]
        );
        self::$cache = null;
    }
}

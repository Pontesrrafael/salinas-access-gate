<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Limitador de tentativas persistido no banco (funciona com vários processos PHP-FPM).
 */
final class RateLimiter
{
    public static function tooMany(string $chave, int $max, int $janelaMin): bool
    {
        $n = (int) Database::value(
            'SELECT COUNT(*) FROM rate_limits WHERE chave = ? AND criado_em >= (NOW() - INTERVAL ? MINUTE)',
            [$chave, $janelaMin]
        );
        return $n >= $max;
    }

    public static function hit(string $chave): void
    {
        Database::run('INSERT INTO rate_limits (chave) VALUES (?)', [$chave]);
        // Limpeza oportunista (1% das requisições)
        if (random_int(1, 100) === 1) {
            Database::run('DELETE FROM rate_limits WHERE criado_em < (NOW() - INTERVAL 1 DAY)');
        }
    }

    public static function clear(string $chave): void
    {
        Database::run('DELETE FROM rate_limits WHERE chave = ?', [$chave]);
    }
}

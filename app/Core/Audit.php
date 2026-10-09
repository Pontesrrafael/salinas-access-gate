<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Trilha de auditoria append-only (tabela audit_log protegida por trigger).
 * Movimentações de portaria são auditadas pela própria trigger do banco.
 */
final class Audit
{
    public static function log(string $entidade, ?string $registroId, string $acao, array $detalhes = [], ?string $usuarioId = null): void
    {
        Database::insert('audit_log', [
            'id'          => Uuid::v4(),
            'entidade'    => $entidade,
            'registro_id' => $registroId,
            'acao'        => $acao,
            'usuario_id'  => $usuarioId ?? Auth::id(),
            'detalhes'    => $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null,
            'ip'          => PHP_SAPI === 'cli' ? 'cli' : Http::clientIp(),
        ]);
    }
}

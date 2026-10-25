<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;

/**
 * Retenção e anonimização (LGPD arts. 15 e 16 · ISO 27001 A.8.10).
 * Prazos em Configurações → Privacidade. Rodado diariamente por `php bin/console.php aplicar-retencao`.
 *
 * As tabelas imutáveis só aceitam as mudanças abaixo com @sag_retencao = 1 (migração 005):
 * limpar observações de registros, apagar fotos vencidas e auditoria com mais de 6 meses.
 */
final class Retencao
{
    private const LOTE = 2000;

    /** @return array<string,int> contagem por etapa */
    public static function aplicar(bool $simular = false): array
    {
        $prazos = self::prazos();
        $r = [
            'fotos'        => self::fotos($prazos['fotos_dias'], $simular),
            'solicitacoes' => self::solicitacoes($prazos['solicitacoes_meses'], $simular),
            'cadastros'    => self::cadastros($prazos['cadastros_meses'], $simular),
            'registros'    => self::registros($prazos['registros_meses'], $simular),
            'auditoria'    => self::auditoria($prazos['auditoria_meses'], $simular),
        ];
        if (!$simular && array_sum($r) > 0) {
            Audit::log('lgpd', null, 'RETENCAO_APLICADA', ['prazos' => $prazos, 'resultado' => $r], null);
        }
        return $r;
    }

    /** @return array{fotos_dias:int,solicitacoes_meses:int,cadastros_meses:int,registros_meses:int,auditoria_meses:int} */
    public static function prazos(): array
    {
        return [
            'fotos_dias'         => max(7, Settings::int('retencao_fotos_dias', 180)),
            'solicitacoes_meses' => max(1, Settings::int('retencao_solicitacoes_meses', 12)),
            'cadastros_meses'    => max(6, Settings::int('retencao_cadastros_meses', 24)),
            'registros_meses'    => max(6, Settings::int('retencao_registros_meses', 24)),
            'auditoria_meses'    => max(6, Settings::int('retencao_auditoria_meses', 60)),
        ];
    }

    /** Fotos da portaria: apaga arquivo e metadados. */
    private static function fotos(int $dias, bool $simular): int
    {
        $sql = 'FROM registro_anexos WHERE created_at < (NOW() - INTERVAL ? DAY)';
        if ($simular) {
            return (int) Database::value('SELECT COUNT(*) ' . $sql, [$dias]);
        }
        $total = 0;
        do {
            $lote = Database::all('SELECT id, storage_path ' . $sql . ' LIMIT ' . self::LOTE, [$dias]);
            foreach ($lote as $a) {
                $p = Upload::caminho($a['storage_path']);
                if ($p !== null) {
                    @unlink($p);
                }
            }
            if ($lote) {
                $ids = array_column($lote, 'id');
                self::comPermissao(fn () => Database::run('DELETE FROM registro_anexos WHERE id IN (' . Database::in($ids) . ')', $ids));
            }
            $total += count($lote);
        } while (count($lote) === self::LOTE);
        return $total;
    }

    /** Solicitações encerradas: mantém data, área, status e vínculo; remove dados do solicitante. */
    private static function solicitacoes(int $meses, bool $simular): int
    {
        $where = "anonimizado_em IS NULL AND data_prevista < (CURDATE() - INTERVAL ? MONTH)
                  AND status IN ('aprovada','recusada','realizada','expirada')";
        if ($simular) {
            return (int) Database::value("SELECT COUNT(*) FROM solicitacoes_acesso WHERE {$where}", [$meses]);
        }
        return Database::run(
            "UPDATE solicitacoes_acesso SET visitante_nome = 'Solicitante anonimizado', visitante_doc = NULL,
                    contato_telefone = NULL, contato_email = NULL, motivo = NULL, status_observacao = NULL,
                    motivo_recusa = NULL, token_externo = NULL, anonimizado_em = NOW()
              WHERE {$where}",
            [$meses]
        )->rowCount();
    }

    /**
     * Visitantes e terceiros sem acesso no prazo, sem usuário vinculado e fora do site:
     * o registro continua (o histórico aponta para ele), mas sem dados que identifiquem a pessoa.
     */
    private static function cadastros(int $meses, bool $simular): int
    {
        $ids = array_column(Database::all(
            "SELECT p.id FROM pessoas p
              WHERE p.anonimizado_em IS NULL AND p.categoria IN ('visitante','terceiro')
                AND p.created_at < (NOW() - INTERVAL ? MONTH)
                AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.pessoa_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM registros_acesso r WHERE r.pessoa_id = p.id AND r.data_hora >= (NOW() - INTERVAL ? MONTH))
                AND NOT EXISTS (SELECT 1 FROM vw_presenca_dentro v WHERE v.pessoa_id = p.id)",
            [$meses, $meses]
        ), 'id');
        if ($simular || !$ids) {
            return count($ids);
        }
        foreach (array_chunk($ids, self::LOTE) as $lote) {
            Database::transaction(function () use ($lote): void {
                $in = Database::in($lote);
                Database::run("DELETE FROM documentos WHERE pessoa_id IN ({$in})", $lote);
                Database::run(
                    "UPDATE pessoas SET nome = CONCAT('Pessoa anonimizada ', LEFT(id, 8)), cpf_enc = NULL, cpf_hash = NULL,
                            rg = NULL, matricula = NULL, funcao = NULL, telefone = NULL, telefone_principal = NULL,
                            email_principal = NULL, whatsapp = NULL, observacoes_contato = NULL, destino_padrao = NULL,
                            responsavel_interno_padrao = NULL, observacoes_portaria_padrao = NULL, observacoes = NULL,
                            ativo = 0, anonimizado_em = NOW()
                      WHERE id IN ({$in})",
                    $lote
                );
            });
        }
        return count($ids);
    }

    /** Registros de acesso: mantém quem/onde/quando (prova do acesso) e apaga as observações livres. */
    private static function registros(int $meses, bool $simular): int
    {
        $where = 'observacoes IS NOT NULL AND data_hora < (NOW() - INTERVAL ? MONTH)';
        if ($simular) {
            return (int) Database::value("SELECT COUNT(*) FROM registros_acesso WHERE {$where}", [$meses]);
        }
        return self::emLotes("UPDATE registros_acesso SET observacoes = NULL WHERE {$where}", [$meses]);
    }

    private static function auditoria(int $meses, bool $simular): int
    {
        $where = 'data_hora < (NOW() - INTERVAL ? MONTH)';
        if ($simular) {
            return (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE {$where}", [$meses]);
        }
        return self::emLotes("DELETE FROM audit_log WHERE {$where}", [$meses]);
    }

    private static function emLotes(string $sql, array $params): int
    {
        $total = 0;
        do {
            $n = self::comPermissao(fn () => Database::run($sql . ' LIMIT ' . self::LOTE, $params)->rowCount());
            $total += $n;
        } while ($n === self::LOTE);
        return $total;
    }

    /** Liga a exceção dos triggers só durante a instrução e sempre desliga depois. */
    private static function comPermissao(callable $fn): mixed
    {
        Database::run('SET @sag_retencao = 1');
        try {
            return $fn();
        } finally {
            Database::run('SET @sag_retencao = NULL');
        }
    }
}

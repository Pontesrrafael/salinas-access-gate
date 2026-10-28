<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Retenção e anonimização (LGPD arts. 15 e 16 · ISO 27001 A.8.10).
 * Prazos em Configurações → Privacidade / Chamados. Rodado diariamente por `php bin/console.php aplicar-retencao`.
 *
 * As tabelas imutáveis só aceitam as mudanças abaixo com @sag_retencao = 1 (migração 005):
 * limpar observações de registros, apagar fotos vencidas e auditoria com mais de 6 meses.
 */
final class Retencao
{
    private const LOTE = 2000;

    public const TEXTO_REMOVIDO = '[removido por retenção]';
    public const CHAMADOS_MIN_MESES = 6;
    public const EMAIL_FILA_DIAS = 30;

    /** @return array<string,int> contagem por etapa */
    public static function aplicar(bool $simular = false): array
    {
        $prazos = self::prazos();
        $r = [
            'fotos'        => self::fotos($prazos['fotos_dias'], $simular),
            'solicitacoes' => self::solicitacoes($prazos['solicitacoes_meses'], $simular),
            'cadastros'    => self::cadastros($prazos['cadastros_meses'], $simular),
            'registros'    => self::registros($prazos['registros_meses'], $simular),
            'chamados'     => self::chamados($prazos['chamados_meses'], $simular),
            'email_fila'   => self::emailFila($prazos['email_fila_dias'], $simular),
            'auditoria'    => self::auditoria($prazos['auditoria_meses'], $simular),
        ];
        if (!$simular && array_sum($r) > 0) {
            Audit::log('lgpd', null, 'RETENCAO_APLICADA', ['prazos' => $prazos, 'resultado' => $r], null);
        }
        return $r;
    }

    /** @return array{fotos_dias:int,solicitacoes_meses:int,cadastros_meses:int,registros_meses:int,chamados_meses:int,email_fila_dias:int,auditoria_meses:int} */
    public static function prazos(): array
    {
        return [
            'fotos_dias'         => max(7, Settings::int('retencao_fotos_dias', 180)),
            'solicitacoes_meses' => max(1, Settings::int('retencao_solicitacoes_meses', 12)),
            'cadastros_meses'    => max(6, Settings::int('retencao_cadastros_meses', 24)),
            'registros_meses'    => max(6, Settings::int('retencao_registros_meses', 24)),
            'chamados_meses'     => self::normalizarPrazoChamados(Settings::int('retencao_chamados_meses', 24)),
            'email_fila_dias'    => self::EMAIL_FILA_DIAS,
            'auditoria_meses'    => max(6, Settings::int('retencao_auditoria_meses', 60)),
        ];
    }

    public static function normalizarPrazoChamados(int $meses): int
    {
        return max(self::CHAMADOS_MIN_MESES, $meses);
    }

    /**
     * Monta a consulta de seleção ou contagem de chamados fechados elegíveis à retenção.
     * @return array{sql:string,params:array<int,int>}
     */
    public static function consultaCandidatosChamados(int $meses, bool $contagem = false): array
    {
        $mesesNorm = self::normalizarPrazoChamados($meses);
        $projecao = $contagem ? 'COUNT(*)' : 'id';
        $sql = "SELECT {$projecao} FROM chamados
                 WHERE status = 'fechado'
                   AND anonimizado_em IS NULL
                   AND fechado_em IS NOT NULL
                   AND fechado_em < (NOW() - INTERVAL ? MONTH)";
        return ['sql' => $sql, 'params' => [$mesesNorm]];
    }

    /**
     * Monta o plano de consultas SQL parametrizadas para anonimizar um lote de chamados fechados.
     *
     * @param string[] $ids
     * @return array{
     *   anexos_select: array{sql:string,params:array<int,mixed>},
     *   anexos_delete: array{sql:string,params:array<int,mixed>},
     *   respostas_update: array{sql:string,params:array<int,mixed>},
     *   acompanhamentos_update: array{sql:string,params:array<int,mixed>},
     *   chamados_update: array{sql:string,params:array<int,mixed>}
     * }
     */
    public static function planoSqlChamados(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids), static fn (string $v): bool => $v !== ''));
        if ($ids === []) {
            throw new InvalidArgumentException('Lista de IDs de chamados vazia para retenção.');
        }
        $in = Database::in($ids);
        return [
            'anexos_select' => [
                'sql'    => "SELECT id, storage_path FROM chamado_anexos WHERE chamado_id IN ({$in})",
                'params' => $ids,
            ],
            'anexos_delete' => [
                'sql'    => "DELETE FROM chamado_anexos WHERE chamado_id IN ({$in})",
                'params' => $ids,
            ],
            'respostas_update' => [
                'sql'    => "UPDATE chamado_respostas SET valor = ? WHERE chamado_id IN ({$in})",
                'params' => [self::TEXTO_REMOVIDO, ...$ids],
            ],
            'acompanhamentos_update' => [
                'sql'    => "UPDATE chamado_acompanhamentos SET texto = ? WHERE chamado_id IN ({$in})",
                'params' => [self::TEXTO_REMOVIDO, ...$ids],
            ],
            'chamados_update' => [
                'sql'    => "UPDATE chamados SET descricao = NULL, beneficiario_nome = NULL, anonimizado_em = NOW() WHERE id IN ({$in})",
                'params' => $ids,
            ],
        ];
    }

    /**
     * Monta a consulta de contagem ou exclusão de e-mails enviados há mais de N dias.
     * @return array{sql:string,params:array<int,int>}
     */
    public static function consultaEmailFila(int $dias = self::EMAIL_FILA_DIAS, bool $contagem = false): array
    {
        $diasNorm = max(1, $dias);
        $where = 'enviado_em IS NOT NULL AND enviado_em < (NOW() - INTERVAL ? DAY)';
        $sql = $contagem
            ? "SELECT COUNT(*) FROM email_fila WHERE {$where}"
            : "DELETE FROM email_fila WHERE {$where} LIMIT " . self::LOTE;
        return ['sql' => $sql, 'params' => [$diasNorm]];
    }

    /**
     * Regra pura (sem banco): avalia se um chamado está elegível para anonimização por retenção.
     * @param array<string, mixed> $chamado
     */
    public static function elegivelChamado(array $chamado, DateTimeImmutable $agora, int $meses = 24): bool
    {
        $mesesNorm = self::normalizarPrazoChamados($meses);
        if (($chamado['status'] ?? '') !== 'fechado') {
            return false;
        }
        if (!empty($chamado['anonimizado_em'])) {
            return false;
        }
        if (empty($chamado['fechado_em'])) {
            return false;
        }
        $fechadoEm = new DateTimeImmutable((string) $chamado['fechado_em']);
        $corte = $agora->modify('-' . $mesesNorm . ' months');
        return $fechadoEm < $corte;
    }

    /**
     * Regra pura (sem banco): aplica a transformação de retenção sobre um chamado e seus filhos,
     * preservando número, área, categoria, datas, status, prioridade e nota de satisfação (estatística).
     *
     * @param array<string, mixed> $chamado
     * @return array<string, mixed>
     */
    public static function anonimizarRegistroChamado(array $chamado, DateTimeImmutable $agora): array
    {
        $out = $chamado;
        $out['descricao'] = null;
        $out['beneficiario_nome'] = null;
        $out['anonimizado_em'] = $agora->format('Y-m-d H:i:s');
        $out['anexos'] = [];

        if (isset($out['respostas']) && is_array($out['respostas'])) {
            foreach ($out['respostas'] as &$resp) {
                if (is_array($resp)) {
                    $resp['valor'] = self::TEXTO_REMOVIDO;
                }
            }
            unset($resp);
        }

        if (isset($out['acompanhamentos']) && is_array($out['acompanhamentos'])) {
            foreach ($out['acompanhamentos'] as &$ac) {
                if (is_array($ac)) {
                    $ac['texto'] = self::TEXTO_REMOVIDO;
                }
            }
            unset($ac);
        }

        return $out;
    }

    /**
     * Regra pura (sem banco): avalia se um item de email_fila enviado deve ser expurgado.
     * @param array<string, mixed> $item
     */
    public static function elegivelEmailFila(array $item, DateTimeImmutable $agora, int $dias = self::EMAIL_FILA_DIAS): bool
    {
        if (empty($item['enviado_em'])) {
            return false;
        }
        $enviadoEm = new DateTimeImmutable((string) $item['enviado_em']);
        $corte = $agora->modify('-' . max(1, $dias) . ' days');
        return $enviadoEm < $corte;
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

    /**
     * Chamados fechados há mais de N meses (mínimo 6):
     * - apaga arquivos físicos e linhas de chamado_anexos;
     * - limpa descricao e beneficiario_nome para NULL;
     * - substitui chamado_respostas.valor e chamado_acompanhamentos.texto por '[removido por retenção]';
     * - marca anonimizado_em = NOW();
     * - mantém número, área, categoria, datas, status, prioridade e nota de satisfação (estatística).
     */
    private static function chamados(int $meses, bool $simular): int
    {
        $mesesNorm = self::normalizarPrazoChamados($meses);
        if ($simular) {
            $c = self::consultaCandidatosChamados($mesesNorm, true);
            return (int) Database::value($c['sql'], $c['params']);
        }

        $c = self::consultaCandidatosChamados($mesesNorm, false);
        $ids = array_column(Database::all($c['sql'], $c['params']), 'id');
        if (!$ids) {
            return 0;
        }

        foreach (array_chunk($ids, self::LOTE) as $lote) {
            $plano = self::planoSqlChamados($lote);
            $anexos = Database::all($plano['anexos_select']['sql'], $plano['anexos_select']['params']);
            Database::transaction(function () use ($plano): void {
                Database::run($plano['anexos_delete']['sql'], $plano['anexos_delete']['params']);
                Database::run($plano['respostas_update']['sql'], $plano['respostas_update']['params']);
                Database::run($plano['acompanhamentos_update']['sql'], $plano['acompanhamentos_update']['params']);
                Database::run($plano['chamados_update']['sql'], $plano['chamados_update']['params']);
            });
            foreach ($anexos as $a) {
                $p = Upload::caminho((string) ($a['storage_path'] ?? ''));
                if ($p !== null) {
                    @unlink($p);
                }
            }
        }

        return count($ids);
    }

    /** E-mails já enviados na fila há mais de 30 dias: expurga os registros antigos. */
    private static function emailFila(int $dias, bool $simular): int
    {
        $c = self::consultaEmailFila($dias, $simular);
        if ($simular) {
            return (int) Database::value($c['sql'], $c['params']);
        }
        $total = 0;
        do {
            $n = Database::run($c['sql'], $c['params'])->rowCount();
            $total += $n;
        } while ($n === self::LOTE);
        return $total;
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

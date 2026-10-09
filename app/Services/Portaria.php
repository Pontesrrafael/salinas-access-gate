<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Regras de negócio da portaria — aplicadas NO SERVIDOR
 * (no sistema anterior estas validações existiam apenas no navegador).
 *
 * Entrada bloqueada quando:
 *  1. pessoa inativa;
 *  2. terceiro com documento (ASO/NR/Integração/CNH) vencido;
 *  3. visitante/terceiro sem solicitação APROVADA para hoje;
 *  4. solicitação de atividade de risco sem verificação SSMA "Apto" vigente;
 *  5. veículo inativo; destino vazio quando a configuração exigir.
 * Saída bloqueada quando a pessoa (ou o veículo) não está presente no local.
 */
final class Portaria
{
    public static function pessoa(string $id): ?array
    {
        return Database::one(
            'SELECT id, nome, categoria, empresa, telefone, telefone_principal, email_principal, whatsapp,
                    destino_padrao, responsavel_interno_padrao, observacoes_portaria_padrao, ativo, cpf_enc, matricula
               FROM pessoas WHERE id = ?',
            [$id]
        );
    }

    public static function documentos(string $pessoaId): array
    {
        $docs = Database::all('SELECT tipo, numero, validade FROM documentos WHERE pessoa_id = ? ORDER BY validade', [$pessoaId]);
        foreach ($docs as &$d) {
            $d['status'] = status_documento($d['validade']);
        }
        return $docs;
    }

    public static function presente(string $pessoaId, string $localId): ?array
    {
        return Database::one(
            "SELECT registro_id, data_hora, veiculo_id FROM vw_ultimo_mov_pessoa
              WHERE pessoa_id = ? AND local_id = ? AND sentido = 'entrada'",
            [$pessoaId, $localId]
        );
    }

    public static function veiculoPresente(string $veiculoId, string $localId): bool
    {
        return (bool) Database::value(
            "SELECT 1 FROM vw_ultimo_mov_veiculo WHERE veiculo_id = ? AND local_id = ? AND sentido = 'entrada'",
            [$veiculoId, $localId]
        );
    }

    /**
     * Solicitações relevantes para a pessoa HOJE.
     *  - vinculadas: já associadas à pessoa (qualquer status, para exibir situação)
     *  - candidatas: aprovadas hoje sem vínculo cujo documento ou nome confere
     *  - outras:     aprovadas hoje sem vínculo (seleção manual pelo porteiro)
     */
    public static function solicitacoesDoDia(array $pessoa): array
    {
        $sql = 'SELECT s.id, s.visitante_nome, s.visitante_doc, s.empresa, s.status, s.data_prevista, s.atividade_risco,
                       s.pessoa_id, s.motivo, a.nome AS area_nome, u.nome AS responsavel_nome
                  FROM solicitacoes_acesso s
                  LEFT JOIN areas a ON a.id = s.area_id
                  LEFT JOIN usuarios u ON u.id = s.responsavel_interno_id
                 WHERE s.data_prevista = CURDATE() AND s.aguardando_preenchimento = 0 AND ';
        $vinculadas = Database::all($sql . 's.pessoa_id = ? ORDER BY s.created_at DESC', [$pessoa['id']]);
        $livres = Database::all($sql . "s.pessoa_id IS NULL AND s.status = 'aprovada' ORDER BY s.visitante_nome LIMIT 50");

        $cpf = Crypto::decryptCpf($pessoa['cpf_enc'] ?? null) ?? '';
        $nome = self::normalizar($pessoa['nome']);
        $candidatas = $outras = [];
        foreach ($livres as $s) {
            $doc = Crypto::cpfDigits($s['visitante_doc']);
            $confere = ($cpf !== '' && $doc !== '' && $doc === $cpf) || self::normalizar($s['visitante_nome']) === $nome;
            if ($confere) {
                $candidatas[] = $s;
            } else {
                $outras[] = $s;
            }
        }
        return ['vinculadas' => $vinculadas, 'candidatas' => $candidatas, 'outras' => $outras];
    }

    /** Situação SSMA de uma solicitação: null (não exige) | ['apto'=>bool,'motivo'=>?string] */
    public static function ssma(array $sol): ?array
    {
        if ((int) $sol['atividade_risco'] !== 1) {
            return null;
        }
        $v = Database::one(
            'SELECT status, observacao, validade_ate FROM verificacoes_ssma
              WHERE solicitacao_id = ? ORDER BY created_at DESC LIMIT 1',
            [$sol['id']]
        );
        if (!$v) {
            return ['apto' => false, 'motivo' => 'Aguardando verificação do SSMA'];
        }
        if ($v['status'] !== 'apto') {
            return ['apto' => false, 'motivo' => $v['observacao'] ?: 'Reprovado pelo SSMA'];
        }
        if ($v['validade_ate'] !== null && $v['validade_ate'] < date('Y-m-d')) {
            return ['apto' => false, 'motivo' => 'Verificação SSMA vencida em ' . fmt_data($v['validade_ate'])];
        }
        return ['apto' => true, 'motivo' => null];
    }

    /**
     * Análise completa usada pela tela (pré-visualização) e pelo POST (validação final).
     * @return array{pessoa:array,documentos:array,bloqueios:array,avisos:array,presente:?array,
     *               exige_solicitacao:bool,solicitacoes:array,solicitacao_sugerida:?string}
     */
    public static function analisar(string $pessoaId, string $localId, string $sentido, ?string $veiculoId, ?string $solicitacaoId): array
    {
        $p = self::pessoa($pessoaId);
        if (!$p) {
            return ['erro' => 'Pessoa não encontrada'];
        }
        $docs = self::documentos($pessoaId);
        $presente = $localId !== '' ? self::presente($pessoaId, $localId) : null;
        $exigeSol = in_array($p['categoria'], ['visitante', 'terceiro'], true);
        $sols = $exigeSol ? self::solicitacoesDoDia($p) : ['vinculadas' => [], 'candidatas' => [], 'outras' => []];
        $bloqueios = [];
        $avisos = [];

        $veiculo = $veiculoId ? Database::one('SELECT * FROM veiculos WHERE id = ?', [$veiculoId]) : null;
        $local = $localId !== '' ? Database::one('SELECT * FROM locais WHERE id = ?', [$localId]) : null;

        // Sugestão automática: vinculada aprovada > candidata única
        $aprovadasVinc = array_values(array_filter($sols['vinculadas'], fn ($s) => $s['status'] === 'aprovada'));
        $sugerida = $aprovadasVinc[0]['id'] ?? (count($sols['candidatas']) === 1 ? $sols['candidatas'][0]['id'] : null);

        if ($sentido === 'entrada') {
            if ((int) $p['ativo'] !== 1) {
                $bloqueios[] = 'Cadastro da pessoa está INATIVO.';
            }
            if ($p['categoria'] === 'terceiro') {
                $vencidos = array_filter($docs, fn ($d) => $d['status'] === 'vencido');
                if ($vencidos) {
                    $bloqueios[] = 'Documento(s) vencido(s): ' . implode(', ', array_map(fn ($d) => TIPOS_DOC[$d['tipo']] ?? $d['tipo'], $vencidos)) . '.';
                }
            }
            $aVencer = array_filter($docs, fn ($d) => $d['status'] === 'proximo_vencimento');
            if ($aVencer) {
                $avisos[] = 'Documento(s) vencendo em até 30 dias: ' . implode(', ', array_map(fn ($d) => (TIPOS_DOC[$d['tipo']] ?? $d['tipo']) . ' (' . fmt_data($d['validade']) . ')', $aVencer)) . '.';
            }
            if ($exigeSol) {
                $solId = $solicitacaoId ?: $sugerida;
                $sol = null;
                if ($solId) {
                    foreach ([...$sols['vinculadas'], ...$sols['candidatas'], ...$sols['outras']] as $s) {
                        if ($s['id'] === $solId) {
                            $sol = $s;
                            break;
                        }
                    }
                }
                if (!$sol) {
                    $bloqueios[] = 'Visitante/terceiro sem solicitação de acesso APROVADA para hoje.';
                } elseif ($sol['status'] !== 'aprovada') {
                    $bloqueios[] = 'A solicitação selecionada está "' . (STATUS_SOLICITACAO[$sol['status']] ?? $sol['status']) . '" — é necessário estar APROVADA.';
                } else {
                    $ssma = self::ssma($sol);
                    if ($ssma !== null && !$ssma['apto']) {
                        $bloqueios[] = 'Bloqueado pelo SSMA: ' . $ssma['motivo'] . '.';
                    }
                }
            }
            if ($veiculoId) {
                if (!$veiculo) {
                    $bloqueios[] = 'Veículo não encontrado.';
                } elseif ((int) $veiculo['ativo'] !== 1) {
                    $bloqueios[] = 'Veículo inativo — entrada não permitida.';
                } else {
                    if ($p['empresa'] && $veiculo['empresa'] && mb_strtolower(trim($p['empresa'])) !== mb_strtolower(trim($veiculo['empresa']))) {
                        $avisos[] = "Placa vinculada a outra empresa ({$veiculo['empresa']}) — diferente da pessoa ({$p['empresa']}).";
                    }
                    if ($local && $local['tipo'] === 'mina') {
                        if ((int) $veiculo['comissionado_mina'] !== 1) {
                            $avisos[] = 'Veículo NÃO comissionado para a mina.';
                        } elseif ($veiculo['comissionamento_validade'] && $veiculo['comissionamento_validade'] < date('Y-m-d')) {
                            $avisos[] = 'Comissionamento do veículo vencido em ' . fmt_data($veiculo['comissionamento_validade']) . '.';
                        }
                    }
                }
            }
            if ($presente) {
                $avisos[] = 'Esta pessoa já consta como PRESENTE neste local desde ' . fmt_data_hora($presente['data_hora']) . ' (saída não registrada).';
            }
        } else {
            if (!$presente) {
                $bloqueios[] = 'Pessoa não está registrada como presente neste local.';
            }
            if ($veiculoId && $localId !== '' && !self::veiculoPresente($veiculoId, $localId)) {
                $bloqueios[] = 'Veículo não está registrado como presente neste local.';
            }
        }

        unset($p['cpf_enc']);
        return [
            'pessoa'               => $p,
            'documentos'           => $docs,
            'bloqueios'            => $bloqueios,
            'avisos'               => $avisos,
            'presente'             => $presente,
            'exige_solicitacao'    => $exigeSol,
            'solicitacoes'         => $sols,
            'solicitacao_sugerida' => $sugerida,
        ];
    }

    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t !== false ? preg_replace('/[^a-z0-9 ]/', '', $t) ?? $s : $s;
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Uuid;

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
 *
 * Alertas críticos (não bloqueiam, mas exigem confirmação explícita do porteiro, registrada):
 *  - colaborador/visitante com documento vencido;
 *  - visitante/terceiro sem integração de segurança vigente em local do tipo mina;
 *  - veículo sem comissionamento (ou vencido) para a mina.
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
     * @return array{pessoa:array,documentos:array,bloqueios:array,alertas:array,avisos:array,presente:?array,
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
        $alertas = [];

        $veiculo = $veiculoId ? Database::one('SELECT * FROM veiculos WHERE id = ?', [$veiculoId]) : null;
        $local = $localId !== '' ? Database::one('SELECT * FROM locais WHERE id = ?', [$localId]) : null;

        // Sugestão automática: vinculada aprovada > candidata única
        $aprovadasVinc = array_values(array_filter($sols['vinculadas'], fn ($s) => $s['status'] === 'aprovada'));
        $sugerida = $aprovadasVinc[0]['id'] ?? (count($sols['candidatas']) === 1 ? $sols['candidatas'][0]['id'] : null);

        if ($sentido === 'entrada') {
            if ((int) $p['ativo'] !== 1) {
                $bloqueios[] = 'Cadastro da pessoa está INATIVO.';
            }
            $vencidos = array_filter(self::maisRecentes($docs), fn ($d) => $d['status'] === 'vencido');
            if ($vencidos) {
                $lista = implode(', ', array_map(fn ($d) => TIPOS_DOC[$d['tipo']] ?? $d['tipo'], $vencidos));
                if ($p['categoria'] === 'terceiro') {
                    $bloqueios[] = 'Documento(s) vencido(s): ' . $lista . '.';
                } else {
                    $alertas[] = 'Documento(s) VENCIDO(S): ' . $lista . '.';
                }
            }
            if ($local && $local['tipo'] === 'mina' && in_array($p['categoria'], ['visitante', 'terceiro'], true)
                && Settings::get('alerta_sem_integracao_mina', '1') === '1') {
                $integracao = array_filter($docs, fn ($d) => $d['tipo'] === 'integracao' && $d['status'] !== 'vencido');
                if (!$integracao) {
                    $alertas[] = 'SEM INTEGRAÇÃO de segurança vigente para entrar na mina.';
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
                            $alertas[] = 'Veículo ' . $veiculo['placa'] . ' NÃO COMISSIONADO para a mina.';
                        } elseif ($veiculo['comissionamento_validade'] && $veiculo['comissionamento_validade'] < date('Y-m-d')) {
                            $alertas[] = 'Comissionamento do veículo ' . $veiculo['placa'] . ' VENCIDO em ' . fmt_data($veiculo['comissionamento_validade']) . '.';
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
            'alertas'              => $alertas,
            'avisos'               => $avisos,
            'presente'             => $presente,
            'exige_solicitacao'    => $exigeSol,
            'solicitacoes'         => $sols,
            'solicitacao_sugerida' => $sugerida,
        ];
    }

    /** Documento mais recente de cada tipo (um ASO antigo vencido não conta se há um novo válido). */
    private static function maisRecentes(array $docs): array
    {
        $porTipo = [];
        foreach ($docs as $d) {
            if (!isset($porTipo[$d['tipo']]) || $d['validade'] > $porTipo[$d['tipo']]['validade']) {
                $porTipo[$d['tipo']] = $d;
            }
        }
        return array_values($porTipo);
    }

    /**
     * Grava uma movimentação com todas as validações do servidor.
     *
     * $in: local_id, sentido, pessoa_id, veiculo_id?, solicitacao_id?, destino?, responsavel_interno_id?,
     *      responsavel_interno?, observacoes?, ciente_alertas (bool), registro_id? (UUID gerado pelo navegador,
     *      garante idempotência), offline_em? ('Y-m-d H:i:s', hora real de um registro feito sem conexão).
     * Registro offline: o porteiro não tinha como consultar o servidor; a movimentação é gravada mesmo com
     * bloqueios (a presença física já aconteceu), e as pendências ficam nas observações e na auditoria.
     *
     * @return array{ok:bool, erro?:string, duplicado?:bool, registro_id?:string, avisos?:array, pendencias?:array,
     *               pessoa_nome?:string, placa?:?string, local_nome?:string}
     */
    public static function registrar(array $in, array $u, array $fotos = [], array $tiposFoto = []): array
    {
        $localId = (string) ($in['local_id'] ?? '');
        $sentido = (string) ($in['sentido'] ?? '');
        $pessoaId = (string) ($in['pessoa_id'] ?? '');
        $veiculoId = ($in['veiculo_id'] ?? '') ?: null;
        $solicitacaoId = ($in['solicitacao_id'] ?? '') ?: null;
        $registroId = ($in['registro_id'] ?? '') ?: null;
        $offlineEm = ($in['offline_em'] ?? '') ?: null;

        if ($registroId !== null) {
            if (!Uuid::valid($registroId)) {
                return ['ok' => false, 'erro' => 'Identificador do registro inválido.'];
            }
            $registroId = strtolower($registroId);
            if (Database::value('SELECT 1 FROM registros_acesso WHERE id = ?', [$registroId])) {
                return ['ok' => true, 'duplicado' => true, 'registro_id' => $registroId];
            }
        }
        $local = Uuid::valid($localId) ? Database::one('SELECT * FROM locais WHERE id = ? AND ativo = 1', [$localId]) : null;
        if (!$local) {
            return ['ok' => false, 'erro' => 'Selecione um local válido.'];
        }
        if (!in_array($sentido, ['entrada', 'saida'], true)) {
            return ['ok' => false, 'erro' => 'Movimentação inválida.'];
        }
        if (!Uuid::valid($pessoaId)) {
            return ['ok' => false, 'erro' => 'Selecione uma pessoa antes de registrar.'];
        }
        if ($veiculoId !== null && !Uuid::valid($veiculoId)) {
            return ['ok' => false, 'erro' => 'Veículo inválido.'];
        }
        if ($solicitacaoId !== null && !Uuid::valid($solicitacaoId)) {
            $solicitacaoId = null;
        }
        $dataHora = null;
        if ($offlineEm !== null) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $offlineEm);
            $agora = new \DateTimeImmutable();
            if ($dt === false || $dt > $agora->modify('+5 minutes') || $dt < $agora->modify('-72 hours')) {
                return ['ok' => false, 'erro' => 'Hora do registro offline inválida (aceito até 72 h atrás).'];
            }
            $dataHora = $dt->format('Y-m-d H:i:s');
        }

        $destino = self::texto($in['destino'] ?? null, 150);
        if ($offlineEm === null && $sentido === 'entrada' && Settings::bool('exigir_destino') && $destino === null) {
            return ['ok' => false, 'erro' => 'Informe o destino/setor da entrada.'];
        }

        $analise = self::analisar($pessoaId, $localId, $sentido, $veiculoId, $solicitacaoId);
        if (isset($analise['erro'])) {
            return ['ok' => false, 'erro' => $analise['erro']];
        }
        $obs = self::texto($in['observacoes'] ?? null, 1500);
        $pendencias = [];
        if ($offlineEm === null) {
            if ($analise['bloqueios']) {
                Audit::log('portaria', $pessoaId, 'TENTATIVA_BLOQUEADA', [
                    'sentido' => $sentido, 'local_id' => $localId, 'motivos' => $analise['bloqueios'],
                ]);
                return ['ok' => false, 'erro' => ($sentido === 'entrada' ? 'Entrada bloqueada: ' : 'Saída não permitida: ') . implode(' ', $analise['bloqueios'])];
            }
            if ($sentido === 'entrada' && $analise['alertas'] && empty($in['ciente_alertas'])) {
                return ['ok' => false, 'erro' => 'Confirme a ciência dos alertas em vermelho antes de registrar a entrada.', 'alertas' => $analise['alertas']];
            }
        } else {
            $pendencias = array_merge($analise['bloqueios'], $sentido === 'entrada' ? $analise['alertas'] : []);
        }
        if ($sentido === 'entrada' && $analise['exige_solicitacao']) {
            $solicitacaoId = $solicitacaoId ?: $analise['solicitacao_sugerida'];
        } elseif ($sentido === 'saida') {
            $solicitacaoId = null;
        }

        $marcas = [];
        if ($sentido === 'entrada' && $analise['alertas'] && $offlineEm === null) {
            $marcas[] = '[Alertas confirmados pelo porteiro: ' . implode(' ', $analise['alertas']) . ']';
        }
        if ($offlineEm !== null) {
            $marcas[] = '[Registrado SEM CONEXÃO em ' . fmt_data_hora($dataHora) . '; sincronizado em ' . date('d/m/Y H:i') . ']';
            if ($pendencias) {
                $marcas[] = '[Pendências na sincronização: ' . implode(' ', $pendencias) . ']';
            }
        }
        if ($marcas) {
            $obs = trim(($obs ?? '') . ' ' . implode(' ', $marcas));
        }

        $respId = (string) ($in['responsavel_interno_id'] ?? '');
        $respNome = self::texto($in['responsavel_interno'] ?? null, 150);
        $resp = Uuid::valid($respId)
            ? Database::one('SELECT id, nome, telefone_principal, email_principal FROM pessoas WHERE id = ?', [$respId])
            : null;

        $registroId ??= Uuid::v4();
        $gravados = [];
        try {
            Database::transaction(function () use ($registroId, $pessoaId, $veiculoId, $localId, $solicitacaoId, $sentido, $destino, $resp, $respNome, $u, $fotos, $tiposFoto, $obs, $dataHora, &$gravados): void {
                $linha = [
                    'id'                           => $registroId,
                    'pessoa_id'                    => $pessoaId,
                    'veiculo_id'                   => $veiculoId,
                    'local_id'                     => $localId,
                    'solicitacao_id'               => $solicitacaoId,
                    'sentido'                      => $sentido,
                    'destino'                      => $destino,
                    'responsavel_interno'          => $resp['nome'] ?? $respNome,
                    'responsavel_interno_id'       => $resp['id'] ?? null,
                    'responsavel_interno_nome'     => $resp['nome'] ?? $respNome,
                    'responsavel_interno_telefone' => $resp['telefone_principal'] ?? null,
                    'responsavel_interno_email'    => $resp['email_principal'] ?? null,
                    'porteiro_id'                  => $u['id'],
                    'observacoes'                  => $obs !== null ? mb_substr($obs, 0, 4000) : null,
                ];
                if ($dataHora !== null) {
                    $linha['data_hora'] = $dataHora;
                }
                Database::insert('registros_acesso', $linha);
                if ($solicitacaoId !== null) {
                    // Vincula a solicitação aprovada do dia à pessoa (somente se ainda livre)
                    Database::run(
                        "UPDATE solicitacoes_acesso SET pessoa_id = ?
                          WHERE id = ? AND pessoa_id IS NULL AND status = 'aprovada' AND data_prevista = ?",
                        [$pessoaId, $solicitacaoId, substr($dataHora ?? date('Y-m-d H:i:s'), 0, 10)]
                    );
                }
                foreach ($fotos as $i => $f) {
                    $meta = Upload::salvar($f);
                    $gravados[] = $meta['storage_path'];
                    $tipo = (string) ($tiposFoto[$i] ?? 'outra');
                    Database::insert('registro_anexos', [
                        'id'                 => Uuid::v4(),
                        'registro_acesso_id' => $registroId,
                        'storage_path'       => $meta['storage_path'],
                        'tipo_foto'          => array_key_exists($tipo, TIPOS_FOTO) ? $tipo : 'outra',
                        'nome_arquivo'       => $meta['nome_arquivo'],
                        'mime_type'          => $meta['mime_type'],
                        'tamanho_bytes'      => $meta['tamanho_bytes'],
                        'sha256'             => $meta['sha256'],
                        'criado_por'         => $u['id'],
                    ]);
                }
            });
        } catch (\PDOException $e) {
            foreach ($gravados as $rel) {
                @unlink(Upload::dir() . '/' . $rel);
            }
            if (($e->errorInfo[1] ?? 0) === 1062 && Database::value('SELECT 1 FROM registros_acesso WHERE id = ?', [$registroId])) {
                return ['ok' => true, 'duplicado' => true, 'registro_id' => $registroId]; // envio repetido em paralelo
            }
            throw $e;
        } catch (\Throwable $e) {
            foreach ($gravados as $rel) {
                @unlink(Upload::dir() . '/' . $rel);
            }
            throw $e;
        }

        if ($sentido === 'entrada' && $analise['alertas'] && $offlineEm === null) {
            Audit::log('registros_acesso', $registroId, 'ALERTA_CONFIRMADO', ['alertas' => $analise['alertas']]);
        }
        if ($offlineEm !== null) {
            Audit::log('registros_acesso', $registroId, 'REGISTRO_OFFLINE', ['registrado_em' => $dataHora, 'pendencias' => $pendencias]);
        }
        $placa = $veiculoId ? Database::value('SELECT placa FROM veiculos WHERE id = ?', [$veiculoId]) : null;
        return [
            'ok' => true, 'registro_id' => $registroId, 'avisos' => $analise['avisos'], 'pendencias' => $pendencias,
            'pessoa_nome' => $analise['pessoa']['nome'], 'placa' => $placa ? (string) $placa : null, 'local_nome' => $local['nome'],
        ];
    }

    /**
     * Saída rápida (1 clique) ou em lote a partir da lista de presentes.
     * O veículo com que a pessoa entrou sai junto, se ainda estiver no local (uma vez por veículo no lote).
     *
     * @param array<array{pessoa_id:string,local_id:string}> $itens
     * @return array{ok:int, falhas:array<string>}
     */
    public static function saidaRapida(array $itens, array $u): array
    {
        $ok = 0;
        $falhas = [];
        $veiculosSaindo = [];
        $lote = count($itens) > 1;
        foreach ($itens as $it) {
            $pessoaId = $it['pessoa_id'];
            $localId = $it['local_id'];
            $presente = self::presente($pessoaId, $localId);
            $nome = (string) (Database::value('SELECT nome FROM pessoas WHERE id = ?', [$pessoaId]) ?? 'Pessoa');
            if (!$presente) {
                $falhas[] = $nome . ': não está presente no local (saída já registrada?).';
                continue;
            }
            $veiculoId = $presente['veiculo_id'];
            if ($veiculoId !== null && (isset($veiculosSaindo[$veiculoId . $localId]) || !self::veiculoPresente($veiculoId, $localId))) {
                $veiculoId = null;
            }
            $r = self::registrar([
                'local_id' => $localId, 'sentido' => 'saida', 'pessoa_id' => $pessoaId, 'veiculo_id' => $veiculoId,
                'observacoes' => $lote ? 'Saída em lote (' . count($itens) . ' pessoas).' : 'Saída rápida pela lista de presentes.',
            ], $u);
            if ($r['ok']) {
                $ok++;
                if ($veiculoId !== null) {
                    $veiculosSaindo[$veiculoId . $localId] = true;
                }
            } else {
                $falhas[] = $nome . ': ' . $r['erro'];
            }
        }
        if ($lote) {
            Audit::log('portaria', null, 'SAIDA_EM_LOTE', ['registradas' => $ok, 'falhas' => count($falhas)]);
        }
        return ['ok' => $ok, 'falhas' => $falhas];
    }

    private static function texto(mixed $v, int $max): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t !== false ? preg_replace('/[^a-z0-9 ]/', '', $t) ?? $s : $s;
    }
}

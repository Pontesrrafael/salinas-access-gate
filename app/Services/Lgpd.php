<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;

/**
 * LGPD (Lei 13.709/2018): direitos do titular e política de retenção.
 *
 *  - Relatório do titular (art. 18, II): todos os dados de uma pessoa em JSON.
 *  - Anonimização (art. 16 e 18, IV/VI): remove identificadores de visitantes/terceiros, mantendo
 *    o histórico de acesso (exigido para segurança) sem permitir identificar a pessoa.
 *  - Retenção automática: anonimiza quem não aparece há N meses e apaga fotos com mais de M meses.
 *
 * Limites conhecidos: registros_acesso, verificacoes_ssma, registro_anexos e audit_log são imutáveis
 * (triggers). Eles referenciam a pessoa pelo id; ao anonimizar o cadastro, o nome some de todas as telas.
 * Textos livres já gravados nesses históricos (observações) não são alterados.
 */
final class Lgpd
{
    public const PREFIXO_ANONIMO = 'Titular anonimizado';

    public static function retencaoMeses(): int
    {
        return max(6, Settings::int('lgpd_retencao_meses', 24));
    }

    public static function fotosMeses(): int
    {
        return max(1, Settings::int('lgpd_fotos_meses', 12));
    }

    public static function anonimizada(array $p): bool
    {
        return str_starts_with((string) $p['nome'], self::PREFIXO_ANONIMO);
    }

    /** Motivo pelo qual a pessoa não pode ser anonimizada, ou null se pode. */
    public static function impedimento(array $p): ?string
    {
        if (self::anonimizada($p)) {
            return 'Cadastro já anonimizado.';
        }
        if ($p['categoria'] === 'colaborador') {
            return 'Colaboradores seguem a retenção do RH (obrigações trabalhistas); anonimize pelo processo de desligamento.';
        }
        if (Database::value('SELECT 1 FROM usuarios WHERE pessoa_id = ?', [$p['id']])) {
            return 'A pessoa está vinculada a um usuário do sistema. Desvincule antes.';
        }
        if (Database::value("SELECT 1 FROM vw_presenca_dentro WHERE pessoa_id = ?", [$p['id']])) {
            return 'A pessoa consta como presente em um local. Registre a saída antes.';
        }
        return null;
    }

    /**
     * Anonimiza o cadastro e as solicitações da pessoa e apaga os arquivos de foto dos seus registros.
     * @return array{fotos_apagadas:int, solicitacoes:int}
     */
    public static function anonimizar(string $pessoaId, string $motivo, ?string $usuarioId): array
    {
        $p = Database::one('SELECT * FROM pessoas WHERE id = ?', [$pessoaId]);
        if (!$p) {
            throw new \RuntimeException('Pessoa não encontrada.');
        }
        if ($erro = self::impedimento($p)) {
            throw new \RuntimeException($erro);
        }
        $rotulo = self::PREFIXO_ANONIMO . ' ' . strtoupper(substr(hash('sha256', $pessoaId), 0, 8));
        $sols = 0;
        Database::transaction(function () use ($pessoaId, $rotulo, &$sols): void {
            Database::update('pessoas', [
                'nome' => $rotulo, 'cpf_enc' => null, 'cpf_hash' => null, 'rg' => null, 'matricula' => null, 'funcao' => null,
                'telefone' => null, 'telefone_principal' => null, 'email_principal' => null, 'whatsapp' => null,
                'observacoes_contato' => null, 'destino_padrao' => null, 'responsavel_interno_padrao' => null,
                'observacoes_portaria_padrao' => null, 'observacoes' => null, 'ativo' => 0,
            ], 'id = ?', [$pessoaId]);
            Database::run('UPDATE documentos SET numero = NULL, observacoes = NULL WHERE pessoa_id = ?', [$pessoaId]);
            $sols = Database::update('solicitacoes_acesso', [
                'visitante_nome' => $rotulo, 'visitante_doc' => null, 'contato_telefone' => null, 'contato_email' => null,
                'motivo' => null, 'status_observacao' => null, 'motivo_recusa' => null, 'token_externo' => null,
            ], 'pessoa_id = ?', [$pessoaId]);
        });
        $fotos = self::apagarFotos(
            'SELECT a.storage_path FROM registro_anexos a JOIN registros_acesso r ON r.id = a.registro_acesso_id WHERE r.pessoa_id = ?',
            [$pessoaId]
        );
        Audit::log('pessoas', $pessoaId, 'ANONIMIZACAO_LGPD', ['motivo' => $motivo, 'fotos_apagadas' => $fotos, 'solicitacoes' => $sols], $usuarioId);
        return ['fotos_apagadas' => $fotos, 'solicitacoes' => $sols];
    }

    /**
     * Política de retenção (rodar mensalmente pelo cron).
     * @return array{pessoas:int, solicitacoes:int, fotos:int, simulacao:bool}
     */
    public static function aplicarRetencao(bool $simular = false): array
    {
        $meses = self::retencaoMeses();
        $limite = date('Y-m-d H:i:s', strtotime("-{$meses} months"));
        $candidatas = Database::all(
            "SELECT p.id FROM pessoas p
              WHERE p.categoria IN ('visitante','terceiro')
                AND p.nome NOT LIKE ?
                AND p.updated_at < ?
                AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.pessoa_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM registros_acesso r WHERE r.pessoa_id = p.id AND r.data_hora >= ?)
                AND NOT EXISTS (SELECT 1 FROM solicitacoes_acesso s WHERE s.pessoa_id = p.id AND s.created_at >= ?)
                AND NOT EXISTS (SELECT 1 FROM vw_presenca_dentro d WHERE d.pessoa_id = p.id)",
            [self::PREFIXO_ANONIMO . '%', $limite, $limite, $limite]
        );
        // Solicitações antigas sem pessoa vinculada (visita que não aconteceu, recusada, expirada)
        $solsSoltas = (int) Database::value(
            'SELECT COUNT(*) FROM solicitacoes_acesso WHERE pessoa_id IS NULL AND created_at < ? AND visitante_nome NOT LIKE ?',
            [$limite, self::PREFIXO_ANONIMO . '%']
        );
        $limiteFotos = date('Y-m-d H:i:s', strtotime('-' . self::fotosMeses() . ' months'));
        $sqlFotos = 'SELECT storage_path FROM registro_anexos WHERE created_at < ?';
        if ($simular) {
            $fotos = 0;
            foreach (Database::all($sqlFotos, [$limiteFotos]) as $a) {
                $fotos += Upload::caminho($a['storage_path']) !== null ? 1 : 0;
            }
            return ['pessoas' => count($candidatas), 'solicitacoes' => $solsSoltas, 'fotos' => $fotos, 'simulacao' => true];
        }
        $n = 0;
        foreach ($candidatas as $c) {
            try {
                self::anonimizar($c['id'], "retenção automática ({$meses} meses sem acesso)", null);
                $n++;
            } catch (\RuntimeException) {
                // impedimento surgido entre a seleção e a anonimização: tenta no próximo ciclo
            }
        }
        $sols = Database::run(
            "UPDATE solicitacoes_acesso SET visitante_nome = CONCAT(?, ' ', UPPER(LEFT(SHA2(id, 256), 8))), visitante_doc = NULL,
                    contato_telefone = NULL, contato_email = NULL, motivo = NULL, status_observacao = NULL, motivo_recusa = NULL, token_externo = NULL
              WHERE pessoa_id IS NULL AND created_at < ? AND visitante_nome NOT LIKE ?",
            [self::PREFIXO_ANONIMO, $limite, self::PREFIXO_ANONIMO . '%']
        )->rowCount();
        $fotos = self::apagarFotos($sqlFotos, [$limiteFotos]);
        Audit::log('lgpd', null, 'RETENCAO_APLICADA', [
            'meses' => $meses, 'fotos_meses' => self::fotosMeses(), 'pessoas' => $n, 'solicitacoes' => $sols, 'fotos' => $fotos,
        ], null);
        return ['pessoas' => $n, 'solicitacoes' => $sols, 'fotos' => $fotos, 'simulacao' => false];
    }

    /** Apaga os arquivos (as linhas de registro_anexos são imutáveis e ficam como prova do registro). */
    private static function apagarFotos(string $sql, array $params): int
    {
        $n = 0;
        foreach (Database::all($sql, $params) as $a) {
            $arq = Upload::caminho($a['storage_path']);
            if ($arq !== null && @unlink($arq)) {
                $n++;
            }
        }
        return $n;
    }

    /** Relatório do titular (art. 18, II e V): dados pessoais e histórico, em formato estruturado. */
    public static function relatorioTitular(string $pessoaId): ?array
    {
        $p = Database::one('SELECT * FROM pessoas WHERE id = ?', [$pessoaId]);
        if (!$p) {
            return null;
        }
        $cpf = Crypto::decryptCpf($p['cpf_enc']);
        unset($p['cpf_enc'], $p['cpf_hash']);
        $p['cpf'] = Crypto::formatCpf($cpf);
        return [
            'gerado_em'      => date('c'),
            'controlador'    => Settings::get('org_nome', 'Salinas Gold Mineração LTDA') . ' — CNPJ ' . Settings::get('org_cnpj', ''),
            'encarregado'    => trim(Settings::get('lgpd_encarregado_nome', '') . ' <' . Settings::get('lgpd_encarregado_email', '') . '>'),
            'finalidade'     => 'Controle de acesso físico, segurança patrimonial e segurança do trabalho (SSMA).',
            'bases_legais'   => ['Art. 7º, II — cumprimento de obrigação legal/regulatória (NRs de segurança e saúde do trabalho)',
                                 'Art. 7º, IX — legítimo interesse (segurança das pessoas e do patrimônio)'],
            'retencao'       => 'Cadastro de visitantes/terceiros anonimizado após ' . self::retencaoMeses() . ' meses sem acesso; fotos apagadas após ' . self::fotosMeses() . ' meses.',
            'pessoa'         => $p,
            'documentos'     => Database::all('SELECT tipo, numero, emissao, validade, created_at FROM documentos WHERE pessoa_id = ? ORDER BY validade', [$pessoaId]),
            'solicitacoes'   => Database::all(
                'SELECT s.created_at, s.data_prevista, s.tipo, s.status, s.origem, s.empresa, s.visitante_doc, s.contato_telefone, s.contato_email,
                        s.motivo, s.consentimento_lgpd, a.nome AS area FROM solicitacoes_acesso s LEFT JOIN areas a ON a.id = s.area_id
                  WHERE s.pessoa_id = ? ORDER BY s.created_at', [$pessoaId]),
            'verificacoes_ssma' => Database::all('SELECT created_at, status, validade_ate FROM verificacoes_ssma WHERE pessoa_id = ? ORDER BY created_at', [$pessoaId]),
            'acessos'        => Database::all(
                'SELECT r.data_hora, r.sentido, l.nome AS local, r.destino, v.placa,
                        (SELECT COUNT(*) FROM registro_anexos x WHERE x.registro_acesso_id = r.id) AS fotos
                   FROM registros_acesso r JOIN locais l ON l.id = r.local_id LEFT JOIN veiculos v ON v.id = r.veiculo_id
                  WHERE r.pessoa_id = ? ORDER BY r.data_hora', [$pessoaId]),
        ];
    }
}

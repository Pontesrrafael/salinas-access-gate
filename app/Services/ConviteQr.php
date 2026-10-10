<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Uuid;

/**
 * QR do convite de visita: "SAG1.<id da solicitação>.<assinatura>".
 * A assinatura (HMAC) impede que alguém fabrique um QR para outra solicitação.
 * O QR só libera a entrada se a solicitação estiver APROVADA para hoje — as demais regras
 * (SSMA, documentos, alertas) continuam valendo na portaria.
 */
final class ConviteQr
{
    public const PREFIXO = 'SAG1';
    public const PADRAO = '/^SAG1\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([A-Za-z0-9_-]{16})$/';

    public static function token(string $solicitacaoId): string
    {
        $base = self::PREFIXO . '.' . strtolower($solicitacaoId);
        return $base . '.' . Crypto::assinaturaCurta($base);
    }

    /** Link público do convite: /convite/<id>/<assinatura> (sem pontos na URL). */
    public static function caminhoPublico(string $solicitacaoId): string
    {
        $partes = explode('.', self::token($solicitacaoId));
        return '/convite/' . $partes[1] . '/' . $partes[2];
    }

    /** Devolve o id da solicitação se o token for autêntico; null caso contrário. */
    public static function validar(string $token): ?string
    {
        $token = trim($token);
        if (!preg_match(self::PADRAO, $token, $m) || !Uuid::valid($m[1])) {
            return null;
        }
        $esperado = Crypto::assinaturaCurta(self::PREFIXO . '.' . $m[1]);
        return hash_equals($esperado, $m[2]) ? $m[1] : null;
    }

    /** Solicitação com dados de exibição do convite. */
    public static function solicitacao(string $id): ?array
    {
        return Database::one(
            'SELECT s.id, s.visitante_nome, s.visitante_doc, s.empresa, s.tipo, s.status, s.data_prevista, s.atividade_risco,
                    s.pessoa_id, s.local_id, s.motivo, a.nome AS area_nome, l.nome AS local_nome, u.nome AS responsavel_nome
               FROM solicitacoes_acesso s
               LEFT JOIN areas a ON a.id = s.area_id
               LEFT JOIN locais l ON l.id = s.local_id
               LEFT JOIN usuarios u ON u.id = s.responsavel_interno_id
              WHERE s.id = ?',
            [$id]
        );
    }

    /** Convite válido para exibição: aprovado e com data de hoje em diante. */
    public static function vigente(array $s): bool
    {
        return $s['status'] === 'aprovada' && $s['data_prevista'] >= date('Y-m-d');
    }

    /**
     * Pessoa cadastrada que corresponde à solicitação: vínculo existente, CPF (índice cego) ou nome exato único.
     */
    public static function pessoaDaSolicitacao(array $s): ?string
    {
        if ($s['pessoa_id']) {
            return $s['pessoa_id'];
        }
        $cpf = Crypto::cpfDigits($s['visitante_doc']);
        if (strlen($cpf) === 11 && Crypto::cpfValido($cpf)) {
            $id = Database::value('SELECT id FROM pessoas WHERE cpf_hash = ? AND ativo = 1', [Crypto::cpfHash($cpf)]);
            if ($id) {
                return (string) $id;
            }
        }
        $ids = Database::all(
            "SELECT id FROM pessoas WHERE ativo = 1 AND categoria IN ('visitante','terceiro') AND nome = ? LIMIT 2",
            [trim(preg_replace('/\s+/', ' ', $s['visitante_nome']) ?? '')]
        );
        return count($ids) === 1 ? $ids[0]['id'] : null;
    }
}

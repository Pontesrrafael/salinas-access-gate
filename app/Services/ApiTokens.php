<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit, Database, Http, Uuid};
use InvalidArgumentException;

/** Credenciais independentes da sessão, exclusivas para consulta. */
final class ApiTokens
{
    public const ESCOPOS = [
        'acessos' => 'Registros de entrada e saída',
        'presenca' => 'Pessoas presentes no local',
        'solicitacoes' => 'Solicitações de acesso',
        'chamados' => 'Chamados, SLA e avaliações',
        'cadastros' => 'Cadastros e contagens de pessoas',
    ];

    /** @return array{id:string, token:string} */
    public static function criar(string $nome, array $escopos, ?int $validadeDias, string $criadoPor): array
    {
        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > 120 || preg_match('/[\x00-\x1f\x7f]/u', $nome)) {
            throw new InvalidArgumentException('Informe um nome de até 120 caracteres.');
        }
        if (!Uuid::valid($criadoPor) || !$escopos || ($validadeDias !== null && ($validadeDias < 1 || $validadeDias > 3650))) {
            throw new InvalidArgumentException('Criador, escopos ou validade inválidos.');
        }
        foreach ($escopos as $escopo) {
            if (!is_string($escopo) || !isset(self::ESCOPOS[$escopo])) {
                throw new InvalidArgumentException('Escopo de API inválido.');
            }
        }
        $escopos = array_values(array_unique($escopos));
        // Amostragem por rejeição: 248 é múltiplo de 62, evitando viés do módulo.
        $alfabeto = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $segredo = '';
        while (strlen($segredo) < 40) {
            foreach (unpack('C*', random_bytes(40)) as $byte) {
                if ($byte < 248) { $segredo .= $alfabeto[$byte % 62]; }
                if (strlen($segredo) === 40) { break; }
            }
        }
        $token = 'sag_' . $segredo;
        $id = Uuid::v4();
        Database::transaction(static function () use ($id, $nome, $token, $escopos, $validadeDias, $criadoPor): void {
            Database::run(
                'INSERT INTO api_tokens (id, nome, prefixo, hash, escopos, criado_por, expira_em)
                 VALUES (?, ?, ?, ?, ?, ?, ' . ($validadeDias === null ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ? DAY)') . ')',
                [...[$id, $nome, substr($token, 0, 8), hash('sha256', $token), json_encode($escopos, JSON_THROW_ON_ERROR), $criadoPor],
                    ...($validadeDias === null ? [] : [$validadeDias])]
            );
            Audit::log('api_tokens', $id, 'API_TOKEN_CRIAR', ['escopos' => $escopos, 'validade_dias' => $validadeDias], $criadoPor);
        });
        return ['id' => $id, 'token' => $token];
    }

    /** Metadados administrativos; nunca devolve o hash ou o segredo. */
    public static function listar(): array
    {
        $rows = Database::all('SELECT id, nome, prefixo, escopos, criado_por, criado_em, expira_em,
                                     ultimo_uso_em, ultimo_ip, revogado_em, revogado_por
                                FROM api_tokens ORDER BY criado_em DESC, id');
        foreach ($rows as &$row) { $row['escopos'] = self::escopos($row['escopos']); }
        return $rows;
    }

    public static function revogar(string $id, string $por): void
    {
        if (!Uuid::valid($id) || !Uuid::valid($por)) {
            throw new InvalidArgumentException('Identificador de token ou usuário inválido.');
        }
        Database::transaction(static function () use ($id, $por): void {
            $n = Database::run('UPDATE api_tokens SET revogado_em = NOW(), revogado_por = ? WHERE id = ? AND revogado_em IS NULL', [$por, $id])->rowCount();
            if ($n > 0) { Audit::log('api_tokens', $id, 'API_TOKEN_REVOGAR', [], $por); }
        });
    }

    /** Escopo vazio autentica apenas a identidade, para /status e distinção de 401/403. */
    public static function autenticar(string $tokenClaro, string $escopo): ?array
    {
        if (!preg_match('/^sag_[A-Za-z0-9]{40}$/D', $tokenClaro)) { return null; }
        $hash = hash('sha256', $tokenClaro);
        $row = Database::one('SELECT id, hash, escopos FROM api_tokens
                              WHERE hash = ? AND revogado_em IS NULL AND (expira_em IS NULL OR expira_em > NOW())', [$hash]);
        if (!$row || !hash_equals((string) $row['hash'], $hash)) { return null; }
        $escopos = self::escopos($row['escopos']);
        if ($escopo !== '' && !in_array($escopo, $escopos, true)) { return null; }
        Database::run('UPDATE api_tokens SET ultimo_uso_em = NOW(), ultimo_ip = ?
                       WHERE id = ? AND revogado_em IS NULL AND (expira_em IS NULL OR expira_em > NOW())
                         AND (ultimo_uso_em IS NULL OR ultimo_uso_em <= NOW() - INTERVAL 1 MINUTE)', [Http::clientIp(), $row['id']]);
        return ['id' => $row['id'], 'escopos' => $escopos];
    }

    private static function escopos(mixed $valor): array
    {
        $lista = is_string($valor) ? json_decode($valor, true) : $valor;
        if (!is_array($lista) || !array_is_list($lista)) { return []; }
        return array_values(array_unique(array_filter($lista, static fn ($e): bool => is_string($e) && isset(self::ESCOPOS[$e]))));
    }
}

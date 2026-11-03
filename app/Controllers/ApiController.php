<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{App, Audit, Database, Http, RateLimiter};
use App\Services\{ApiTokens, Chamados};
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** API de consulta: autenticação por cabeçalho, projeções explícitas e paginação limitada. */
final class ApiController
{
    private const DATAS = ['data_hora', 'entrada_em', 'created_at', 'updated_at', 'autorizado_em',
        'prazo_resposta', 'prazo_solucao', 'prazo_solucao_efetivo', 'respondido_em', 'solucionado_em',
        'fechado_em', 'pendente_desde', 'sla_inicio', 'anonimizado_em'];
    private const INTEIROS = ['seq', 'numero', 'urgencia', 'impacto', 'prioridade', 'recorrente', 'ativo',
        'atividade_risco', 'aguardando_preenchimento', 'segundos_pendente', 'prazo_prorrogado_min', 'nota',
        'fechado_automaticamente', 'sla_resposta_min', 'sla_solucao_min', 'usar_horario_comercial', 'total_pessoas'];

    public function consultar(string $recurso): never
    {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Language: pt-BR');
        // Nenhuma origem recebe autorização CORS, mesmo que um middleware anterior a tenha adicionado.
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');
        $token = null;
        $auditado = false;
        try {
            $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (!is_string($authorization) || !preg_match('/^Bearer (sag_[A-Za-z0-9]{40})$/iD', $authorization, $m)
                || !($token = ApiTokens::autenticar($m[1], ''))) {
                header('WWW-Authenticate: Bearer realm="salinas-access-gate"');
                $this->responder(['erro' => 'Credencial inválida ou ausente.'], 401);
            }
            $escopo = explode('/', $recurso)[0];
            // Serializa a contagem por token para que processos concorrentes respeitem o limite.
            $limite = Database::transaction(static function () use ($token): int {
                $ativo = Database::one('SELECT id FROM api_tokens WHERE id = ? AND revogado_em IS NULL
                                        AND (expira_em IS NULL OR expira_em > NOW()) FOR UPDATE', [$token['id']]);
                if (!$ativo) { return 401; }
                $chave = 'api:v1:' . $token['id'];
                if (RateLimiter::tooMany($chave, 120, 1)) { return 429; }
                RateLimiter::hit($chave);
                return 200;
            });
            if ($limite !== 200) {
                if ($limite === 429) { header('Retry-After: 60'); }
                $this->auditar($token, $recurso, 0, $limite);
                $auditado = true;
                $this->responder(['erro' => $limite === 429 ? 'Limite de consultas atingido. Tente novamente em um minuto.' : 'Credencial inválida ou ausente.'], $limite);
            }
            if ($recurso !== 'status' && !in_array($escopo, $token['escopos'], true)) {
                $this->auditar($token, $recurso, 0, 403);
                $auditado = true;
                $this->responder(['erro' => 'Token sem o escopo necessário.'], 403);
            }
            [$pagina, $porPagina, $offset, $datas, $query] = $this->parametros();
            if ($recurso === 'status') {
                $total = 1;
                $dados = $pagina === 1 ? [['versao' => 'v1', 'escopos' => $token['escopos']]] : [];
            } else {
                $def = $this->definicao($recurso);
                $where = ['1 = 1'];
                $params = [];
                foreach (['desde' => [$def['data'], '>='], 'ate' => [$def['data'], '<'], 'alterado_desde' => [$def['alterado'], '>=']] as $nome => [$coluna, $operador]) {
                    if (isset($datas[$nome])) {
                        $where[] = "$coluna $operador ?";
                        $params[] = $datas[$nome];
                    }
                }
                $from = $def['from'] . ' WHERE ' . implode(' AND ', $where);
                $total = (int) Database::value('SELECT COUNT(*) ' . $from, $params);
                $rows = Database::all('SELECT ' . $def['select'] . ' ' . $from . ' ORDER BY ' . $def['ordem']
                    . " LIMIT $porPagina OFFSET $offset", $params);
                $agora = new DateTimeImmutable();
                $dados = array_map(fn (array $r): array => $this->formatar($r, $def['campos'], $recurso, $agora), $rows);
            }
            $proxima = null;
            if ($offset + $porPagina < $total) {
                $query['pagina'] = $pagina + 1;
                $query['por_pagina'] = $porPagina;
                // Usa somente APP_URL confiável; nunca replica token, Host ou parâmetros desconhecidos.
                $proxima = Http::absoluteUrl('/api/v1/' . $recurso) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
            $this->auditar($token, $recurso, count($dados), 200);
            $auditado = true;
            $this->responder(['dados' => $dados, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'total' => $total, 'proxima' => $proxima]);
        } catch (InvalidArgumentException $e) {
            if ($token && !$auditado) { $this->auditar($token, $recurso, 0, 400); }
            $this->responder(['erro' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            // Nunca registra exceção/SQL/parâmetros: podem conter credenciais ou conteúdo de negócio.
            $ref = bin2hex(random_bytes(4));
            App::log('API falhou', ['referencia' => $ref, 'tipo' => get_class($e)]);
            $this->responder(['erro' => 'Consulta indisponível. Referência: ' . $ref], 500);
        }
    }

    private function responder(array $dados, int $status = 200): never
    {
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        http_response_code($status);
        echo $json;
        exit;
    }

    private function auditar(array $token, string $recurso, int $linhas, int $status): void
    {
        // Ator técnico vazio: Audit não tenta resolver um usuário por sessão.
        Audit::log('api_tokens', $token['id'], 'API_CONSULTA', [
            'token_id' => $token['id'], 'endpoint' => '/api/v1/' . $recurso, 'linhas' => $linhas, 'status' => $status,
        ], '');
    }

    /** Valida antes de montar SQL; valores de filtro sempre são parâmetros preparados. */
    private function parametros(): array
    {
        $inteiro = static function (string $nome, int $padrao, int $max): int {
            $v = $_GET[$nome] ?? (string) $padrao;
            if (!is_string($v) || !preg_match('/^[1-9][0-9]{0,8}$/D', $v) || (int) $v > $max) {
                throw new InvalidArgumentException('Paginação inválida: ' . $nome . '.');
            }
            return (int) $v;
        };
        $pagina = $inteiro('pagina', 1, 1000000);
        $porPagina = $inteiro('por_pagina', 500, 5000);
        $datas = $query = [];
        foreach (['desde', 'ate', 'alterado_desde'] as $nome) {
            if (!array_key_exists($nome, $_GET)) { continue; }
            $v = $_GET[$nome];
            if (!is_string($v) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,6})?(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])?)?$/D', $v, $m)
                || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new InvalidArgumentException('Data inválida: ' . $nome . '. Use YYYY-MM-DD ou ISO 8601.');
            }
            $data = new DateTimeImmutable($v, new DateTimeZone(date_default_timezone_get()));
            // Uma data em ate inclui o dia completo; um instante ISO é limite exclusivo.
            if ($nome === 'ate' && strlen($v) === 10) { $data = $data->modify('+1 day'); }
            $datas[$nome] = $data->setTimezone(new DateTimeZone(App::config('app')['db_offset']))->format('Y-m-d H:i:s.u');
            $query[$nome] = $v;
        }
        if (isset($datas['desde'], $datas['ate']) && $datas['desde'] >= $datas['ate']) {
            throw new InvalidArgumentException('O início deve ser anterior ao fim do período.');
        }
        return [$pagina, $porPagina, ($pagina - 1) * $porPagina, $datas, $query];
    }

    private function formatar(array $row, array $campos, string $recurso, DateTimeImmutable $agora): array
    {
        $tzBanco = new DateTimeZone(App::config('app')['db_offset']);
        $tzApp = new DateTimeZone(date_default_timezone_get());
        // As regras de SLA recebem os instantes no fuso da aplicação, como nas telas.
        foreach (self::DATAS as $campo) {
            if (isset($row[$campo])) {
                $row[$campo] = (new DateTimeImmutable($row[$campo], $tzBanco))->setTimezone($tzApp)->format('Y-m-d H:i:s');
            }
        }
        if ($recurso === 'chamados') {
            $row['sla_resposta_status'] = empty($row['prazo_resposta']) ? null : Chamados::situacaoResposta($row, $agora);
            $row['sla_solucao_status'] = empty($row['prazo_solucao']) ? null : Chamados::situacaoSolucao($row, $agora);
            $row['prazo_solucao_efetivo'] = Chamados::prazoSolucao($row, $agora)?->format('Y-m-d H:i:s');
        }
        // Segunda lista de permissão: um campo extra vindo do banco também não é exportado.
        $row = array_intersect_key($row, array_flip($campos));
        foreach ($row as $campo => &$valor) {
            if ($valor === null) { continue; }
            if (in_array($campo, self::DATAS, true)) {
                $valor = (new DateTimeImmutable($valor, $tzApp))->format(DATE_ATOM);
            } elseif (in_array($campo, self::INTEIROS, true)) {
                $valor = (int) $valor;
            }
        }
        return $row;
    }

    /** As colunas e expressões abaixo são constantes do código, nunca entradas do cliente. */
    private function definicao(string $recurso): array
    {
        $pessoa = 'p.id AS pessoa_id, p.nome AS pessoa_nome, p.categoria AS pessoa_categoria, p.empresa AS pessoa_empresa';
        $select = $from = $data = $alterado = $ordem = '';
        $extras = [];
        switch ($recurso) {
            case 'acessos':
                $select = 'r.id, r.seq, r.sentido, r.data_hora, r.created_at, r.local_id, l.nome AS local_nome,
                           r.veiculo_id, r.solicitacao_id, ' . $pessoa;
                $from = 'FROM registros_acesso r JOIN locais l ON l.id = r.local_id LEFT JOIN pessoas p ON p.id = r.pessoa_id';
                $data = 'r.data_hora';
                $alterado = 'GREATEST(r.created_at, l.updated_at, COALESCE(p.updated_at, r.created_at))';
                $ordem = 'r.data_hora, r.seq';
                break;
            case 'presenca':
                $select = 'v.registro_id, v.pessoa_id, v.pessoa_nome, v.pessoa_categoria, v.pessoa_empresa,
                           v.local_id, v.local_nome, v.local_tipo, v.veiculo_id, v.entrada_em';
                $from = 'FROM vw_presenca_dentro v JOIN pessoas p ON p.id = v.pessoa_id JOIN locais l ON l.id = v.local_id';
                $data = 'v.entrada_em';
                $alterado = 'GREATEST(v.entrada_em, p.updated_at, l.updated_at)';
                $ordem = 'v.entrada_em, v.registro_id';
                break;
            case 'solicitacoes':
                $select = 's.id, s.status, s.tipo, s.origem, s.data_prevista, s.atividade_risco, s.aguardando_preenchimento,
                           s.created_at, s.updated_at, s.autorizado_em, s.area_id, a.nome AS area_nome, s.local_id, l.nome AS local_nome,
                           s.pessoa_id, COALESCE(p.nome, s.visitante_nome) AS pessoa_nome, COALESCE(p.categoria, s.tipo) AS pessoa_categoria,
                           COALESCE(p.empresa, s.empresa) AS pessoa_empresa, s.criado_por AS solicitante_id, u.nome AS solicitante_nome';
                $from = 'FROM solicitacoes_acesso s LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN locais l ON l.id = s.local_id
                         LEFT JOIN pessoas p ON p.id = s.pessoa_id LEFT JOIN usuarios u ON u.id = s.criado_por';
                $data = 's.created_at';
                $alterado = 'GREATEST(s.updated_at, COALESCE(a.updated_at, s.updated_at), COALESCE(l.updated_at, s.updated_at),
                             COALESCE(p.updated_at, s.updated_at), COALESCE(u.updated_at, s.updated_at))';
                $ordem = 's.created_at, s.id';
                break;
            case 'chamados':
                $select = 'c.id, c.numero, c.titulo, c.area_id, a.nome AS area_nome, c.categoria_id, cat.nome AS categoria_nome,
                           c.status, c.urgencia, c.impacto, c.prioridade, c.recorrente, c.solicitante_id, u.nome AS solicitante_nome,
                           c.atendente_id, t.nome AS atendente_nome, c.created_at, c.updated_at, c.prazo_resposta, c.prazo_solucao,
                           c.respondido_em, c.solucionado_em, c.fechado_em, c.pendente_desde, c.segundos_pendente, c.sla_inicio,
                           c.prazo_prorrogado_min, c.fechado_automaticamente, c.anonimizado_em';
                $extras = ['sla_resposta_status', 'sla_solucao_status', 'prazo_solucao_efetivo'];
                $from = 'FROM chamados c JOIN servico_areas a ON a.id = c.area_id JOIN servico_categorias cat ON cat.id = c.categoria_id
                         LEFT JOIN usuarios u ON u.id = c.solicitante_id LEFT JOIN usuarios t ON t.id = c.atendente_id';
                $data = 'c.created_at';
                $alterado = 'GREATEST(c.updated_at, a.updated_at, cat.updated_at, COALESCE(u.updated_at, c.updated_at), COALESCE(t.updated_at, c.updated_at))';
                $ordem = 'c.created_at, c.id';
                break;
            case 'chamados/avaliacoes':
                $select = 's.chamado_id, c.numero, c.area_id, a.nome AS area_nome, c.categoria_id, cat.nome AS categoria_nome, s.nota, s.created_at';
                $from = 'FROM chamado_satisfacao s JOIN chamados c ON c.id = s.chamado_id JOIN servico_areas a ON a.id = c.area_id
                         JOIN servico_categorias cat ON cat.id = c.categoria_id';
                $data = 's.created_at';
                $alterado = 'GREATEST(s.created_at, c.updated_at, a.updated_at, cat.updated_at)';
                $ordem = 's.created_at, s.chamado_id';
                break;
            case 'cadastros/locais':
                $select = 'l.id, l.nome, l.tipo, l.ativo, l.created_at, l.updated_at';
                $from = 'FROM locais l';
                $data = 'l.created_at'; $alterado = 'l.updated_at'; $ordem = 'l.created_at, l.id';
                break;
            case 'cadastros/areas':
                $select = 'a.id, a.nome, a.slug, a.ativo, a.sla_resposta_min, a.sla_solucao_min, a.usar_horario_comercial, a.created_at, a.updated_at';
                $from = 'FROM servico_areas a';
                $data = 'a.created_at'; $alterado = 'a.updated_at'; $ordem = 'a.created_at, a.id';
                break;
            case 'cadastros/categorias':
                $select = 'cat.id, cat.area_id, cat.parent_id, cat.nome, cat.ativo, cat.sla_resposta_min, cat.sla_solucao_min,
                           cat.usar_horario_comercial, cat.created_at, cat.updated_at';
                $from = 'FROM servico_categorias cat';
                $data = 'cat.created_at'; $alterado = 'cat.updated_at'; $ordem = 'cat.created_at, cat.id';
                break;
            case 'cadastros/pessoas-resumo':
                $select = 'g.categoria, g.empresa, g.total_pessoas, g.created_at, g.updated_at';
                $from = 'FROM (SELECT categoria, empresa, COUNT(*) AS total_pessoas, MIN(created_at) AS created_at,
                              MAX(updated_at) AS updated_at FROM pessoas GROUP BY categoria, empresa) g';
                $data = 'g.created_at'; $alterado = 'g.updated_at'; $ordem = 'g.categoria, g.empresa';
                break;
            default:
                throw new InvalidArgumentException('Recurso inválido.');
        }
        $campos = array_map(static function (string $campo): string {
            $partes = preg_split('/\s+AS\s+/i', trim($campo));
            return count($partes) > 1 ? end($partes) : substr($partes[0], strpos($partes[0], '.') + 1);
        }, preg_split('/,\s*(?![^()]*\))/', $select));
        $campos = [...$campos, ...$extras];
        if ($recurso === 'chamados') { $select .= ', c.calendario_sla, c.sla_resposta_snapshot'; }
        return compact('select', 'from', 'data', 'alterado', 'ordem', 'campos');
    }
}

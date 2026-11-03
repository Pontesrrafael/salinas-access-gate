<?php
declare(strict_types=1);

/** Testes isolados no estilo de chamados.php: PDO fictício e front controller HTTP real. */
final class ApiTestePdo extends PDO
{
    public array $tokens = [];
    public array $auditoria = [];
    public array $consultas = [];
    public array $limites = [];
    public bool $falharAuditoria = false;
    public bool $falharConsulta = false;
    public int $usosGravados = 0;
    private ?array $snapshot = null;

    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new ApiTesteStmt($this, $query); }
    public function beginTransaction(): bool { $this->snapshot = [$this->tokens, $this->auditoria, $this->limites]; return true; }
    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function commit(): bool { $this->snapshot = null; return true; }
    public function rollBack(): bool { [$this->tokens, $this->auditoria, $this->limites] = $this->snapshot; $this->snapshot = null; return true; }

    public function executar(string $sql, array $params): array
    {
        $this->consultas[] = [$sql, $params];
        if (str_starts_with($sql, 'INSERT INTO api_tokens')) {
            [$id, $nome, $prefixo, $hash, $escopos, $por] = $params;
            $this->tokens[$id] = ['id' => $id, 'nome' => $nome, 'prefixo' => $prefixo, 'hash' => $hash, 'escopos' => $escopos,
                'criado_por' => $por, 'criado_em' => date('Y-m-d H:i:s'), 'expira_em' => isset($params[6]) ? date('Y-m-d H:i:s', time() + $params[6] * 86400) : null,
                'ultimo_uso_em' => null, 'ultimo_ip' => null, 'revogado_em' => null, 'revogado_por' => null];
            return [[], 1];
        }
        if (str_starts_with($sql, 'INSERT INTO `audit_log`')) {
            if ($this->falharAuditoria) { throw new RuntimeException('Auditoria indisponível'); }
            preg_match('/\(([^)]+)\)/', $sql, $m);
            $this->auditoria[] = array_combine(array_map(static fn ($c) => trim($c, ' `'), explode(',', $m[1])), $params);
            return [[], 1];
        }
        if (str_starts_with($sql, 'SELECT id, hash, escopos FROM api_tokens')) {
            foreach ($this->tokens as $row) {
                if ($row['hash'] === $params[0] && $this->ativo($row)) { return [[array_intersect_key($row, array_flip(['id', 'hash', 'escopos']))], 0]; }
            }
            return [[], 0];
        }
        if (str_starts_with($sql, 'SELECT id FROM api_tokens')) {
            $row = $this->tokens[$params[0]] ?? null;
            return [$row && $this->ativo($row) ? [['id' => $row['id']]] : [], 0];
        }
        if (str_starts_with($sql, 'SELECT id, nome, prefixo')) {
            return [array_values(array_map(static function ($r) { unset($r['hash']); return $r; }, $this->tokens)), 0];
        }
        if (str_starts_with($sql, 'UPDATE api_tokens SET revogado_em')) {
            if (!isset($this->tokens[$params[1]]) || $this->tokens[$params[1]]['revogado_em'] !== null) { return [[], 0]; }
            $this->tokens[$params[1]]['revogado_em'] = date('Y-m-d H:i:s');
            $this->tokens[$params[1]]['revogado_por'] = $params[0];
            return [[], 1];
        }
        if (str_starts_with($sql, 'UPDATE api_tokens SET ultimo_uso_em')) {
            $row = &$this->tokens[$params[1]];
            if ($this->ativo($row) && ($row['ultimo_uso_em'] === null || strtotime($row['ultimo_uso_em']) <= time() - 60)) {
                $row['ultimo_uso_em'] = date('Y-m-d H:i:s'); $row['ultimo_ip'] = $params[0]; $this->usosGravados++;
                return [[], 1];
            }
            return [[], 0];
        }
        if (str_contains($sql, 'FROM rate_limits')) {
            if (str_starts_with($sql, 'DELETE')) { return [[], 1]; }
            return [[[($this->limites[$params[0]] ?? 0)]], 0];
        }
        if (str_starts_with($sql, 'INSERT INTO rate_limits')) {
            $this->limites[$params[0]] = ($this->limites[$params[0]] ?? 0) + 1;
            return [[], 1];
        }
        if (!str_starts_with($sql, 'SELECT ')) { throw new RuntimeException('SQL inesperado: ' . $sql); }
        if ($this->falharConsulta) { throw new RuntimeException('Falha simulada com informação que não pode chegar ao cliente'); }
        // Respostas incluem campos proibidos de propósito: a projeção PHP precisa descartá-los.
        $row = [
            'id' => 'fixture-1', 'registro_id' => 'registro-1', 'seq' => 1, 'sentido' => 'entrada', 'numero' => 42,
            'pessoa_id' => 'pessoa-1', 'pessoa_nome' => 'Pessoa de teste', 'pessoa_categoria' => 'terceiro', 'pessoa_empresa' => 'Empresa fictícia',
            'local_id' => 'local-1', 'local_nome' => 'Mina', 'local_tipo' => 'mina', 'veiculo_id' => null, 'solicitacao_id' => null,
            'tipo' => 'mina', 'origem' => 'interna', 'data_prevista' => '2026-11-03', 'atividade_risco' => 0, 'aguardando_preenchimento' => 0,
            'area_id' => 'area-1', 'area_nome' => 'TI', 'nome' => 'TI', 'slug' => 'ti', 'parent_id' => null,
            'categoria_id' => 'categoria-1', 'categoria_nome' => 'Rede', 'titulo' => 'Manutenção de rede', 'status' => 'pendente',
            'urgencia' => 2, 'impacto' => 3, 'prioridade' => 2, 'recorrente' => 0,
            'solicitante_id' => 'usuario-1', 'solicitante_nome' => 'Solicitante fictício', 'atendente_id' => null, 'atendente_nome' => null,
            'created_at' => '2026-11-03 08:00:00', 'updated_at' => '2026-11-03 09:00:00', 'data_hora' => '2026-11-03 08:00:00', 'entrada_em' => '2026-11-03 08:00:00',
            'autorizado_em' => null, 'prazo_resposta' => '2026-11-03 09:00:00', 'prazo_solucao' => '2026-11-03 12:00:00',
            'respondido_em' => '2026-11-03 08:30:00', 'solucionado_em' => null, 'fechado_em' => null,
            'pendente_desde' => '2026-11-03 09:00:00', 'segundos_pendente' => 600, 'prazo_prorrogado_min' => 30, 'sla_inicio' => null,
            'calendario_sla' => null, 'sla_resposta_snapshot' => null, 'anonimizado_em' => null, 'fechado_automaticamente' => 0,
            'chamado_id' => 'chamado-1', 'nota' => 5, 'ativo' => 1, 'sla_resposta_min' => 60, 'sla_solucao_min' => 240,
            'usar_horario_comercial' => null, 'categoria' => 'terceiro', 'empresa' => 'Empresa fictícia', 'total_pessoas' => 3,
            'cpf' => 'SEGREDO-FICTICIO', 'cpf_enc' => 'SEGREDO-FICTICIO', 'cpf_hash' => 'SEGREDO-FICTICIO',
            'telefone' => 'SEGREDO-FICTICIO', 'email' => 'SEGREDO-FICTICIO', 'visitante_doc' => 'SEGREDO-FICTICIO',
            'descricao' => 'SEGREDO-FICTICIO', 'comentario' => 'SEGREDO-FICTICIO', 'valor' => 'SEGREDO-FICTICIO',
            'foto' => 'SEGREDO-FICTICIO', 'storage_path' => 'SEGREDO-FICTICIO', 'token_externo' => 'SEGREDO-FICTICIO',
        ];
        if (str_starts_with($sql, 'SELECT COUNT(*)')) { return [[[3]], 0]; }
        preg_match('/ LIMIT (\d+) OFFSET (\d+)$/', $sql, $m);
        $rows = [];
        foreach (array_slice([1, 2, 3], (int) $m[2], (int) $m[1]) as $n) { $rows[] = array_replace($row, ['id' => 'fixture-' . $n, 'seq' => $n]); }
        return [$rows, 0];
    }

    private function ativo(array $row): bool
    {
        return $row['revogado_em'] === null && ($row['expira_em'] === null || strtotime($row['expira_em']) > time());
    }
}

final class ApiTesteStmt extends PDOStatement
{
    private array $rows = [];
    private int $afetados = 0;
    public function __construct(private ApiTestePdo $pdoTeste, private string $sql) {}
    public function execute(?array $params = null): bool { [$this->rows, $this->afetados] = $this->pdoTeste->executar($this->sql, $params ?? []); return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows ? array_values($this->rows[0])[$column] : false; }
    public function rowCount(): int { return $this->afetados; }
}

if (PHP_SAPI === 'cli-server') {
    // Injeta somente a conexão; inicialização, cookies, cabeçalhos e rotas são os reais.
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require dirname(__DIR__) . '/app/Core/Database.php';
    $pdoTeste = new ApiTestePdo();
    $id = '11111111-1111-4111-8111-111111111111';
    $caso = $_SERVER['HTTP_X_TEST_CASE'] ?? '';
    $tokenTeste = 'sag_' . str_repeat('A', 40);
    $pdoTeste->tokens[$id] = ['id' => $id, 'hash' => hash('sha256', $tokenTeste), 'escopos' => json_encode($caso === 'sem-escopo' ? ['presenca'] : ['acessos', 'presenca', 'solicitacoes', 'chamados', 'cadastros']),
        'expira_em' => $caso === 'expirado' ? '2000-01-01 00:00:00' : null, 'revogado_em' => $caso === 'revogado' ? '2000-01-01 00:00:00' : null, 'ultimo_uso_em' => null];
    if ($caso === 'limite') { $pdoTeste->limites['api:v1:' . $id] = 120; }
    $pdoTeste->falharConsulta = $caso === 'erro';
    (new ReflectionProperty(\App\Core\Database::class, 'pdo'))->setValue(null, $pdoTeste);
    putenv('APP_URL=http://127.0.0.1:' . (int) $_SERVER['SERVER_PORT']);
    putenv('APP_DEBUG=0');
    register_shutdown_function(static function () use ($pdoTeste): void {
        file_put_contents(getenv('API_TEST_TRACE'), json_encode(['audit' => $pdoTeste->auditoria, 'sql' => $pdoTeste->consultas, 'session' => session_status()]));
    });
    require dirname(__DIR__) . '/public/index.php';
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{App, Database};
use App\Services\ApiTokens;

$checks = 0;
function apiCheck(bool $ok, string $mensagem): void
{
    global $checks;
    if (!$ok) { fwrite(STDERR, "API BI: {$checks} verificações passaram antes da falha: {$mensagem}\n"); throw new RuntimeException($mensagem); }
    $checks++;
}
function apiRejeita(Closure $fn): void
{
    try { $fn(); } catch (InvalidArgumentException) { apiCheck(true, 'Validação'); return; }
    throw new RuntimeException('Entrada inválida aceita');
}

$pdoTeste = new ApiTestePdo();
$prop = new ReflectionProperty(Database::class, 'pdo');
$anterior = $prop->getValue();
$prop->setValue(null, $pdoTeste);
$por = '22222222-2222-4222-8222-222222222222';
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
try {
    $r = ApiTokens::criar(' Integração BI ', ['acessos', 'presenca', 'acessos'], 30, $por);
    apiCheck((bool) preg_match('/^sag_[A-Za-z0-9]{40}$/D', $r['token']), 'Formato do token');
    $row = $pdoTeste->tokens[$r['id']];
    apiCheck($row['hash'] === hash('sha256', $r['token']) && !str_contains(json_encode($row), $r['token']), 'Só persiste hash SHA-256; token completo ausente');
    apiCheck($row['prefixo'] === substr($r['token'], 0, 8) && $row['nome'] === 'Integração BI', 'Prefixo e nome');
    apiCheck(json_decode($row['escopos'], true) === ['acessos', 'presenca'], 'Escopos deduplicados');
    apiCheck(strtotime($row['expira_em']) > time() + 29 * 86400, 'Validade em dias');
    apiCheck(ApiTokens::autenticar($r['token'], 'acessos')['id'] === $r['id'], 'Autentica escopo');
    apiCheck(ApiTokens::autenticar($r['token'], '')['escopos'] === ['acessos', 'presenca'], 'Identidade para status');
    apiCheck(ApiTokens::autenticar($r['token'], 'chamados') === null, 'Recusa escopo ausente');
    apiCheck($pdoTeste->usosGravados === 1 && $pdoTeste->tokens[$r['id']]['ultimo_ip'] === '192.0.2.1', 'Atualiza uso no máximo uma vez por minuto');
    $pdoTeste->tokens[$r['id']]['ultimo_uso_em'] = date('Y-m-d H:i:s', time() - 61);
    ApiTokens::autenticar($r['token'], 'presenca');
    apiCheck($pdoTeste->usosGravados === 2, 'Atualiza novamente após janela');
    foreach (['', 'sag_' . str_repeat('A', 39), $r['token'] . 'x', 'Bearer ' . $r['token'], 'sag_' . str_repeat('!', 40), 'sag_' . str_repeat('B', 40)] as $invalido) {
        apiCheck(ApiTokens::autenticar($invalido, '') === null, 'Recusa token inválido');
    }
    $lista = ApiTokens::listar();
    apiCheck(!isset($lista[0]['hash']) && !isset($lista[0]['token']) && is_array($lista[0]['escopos']), 'Listagem sem hash/segredo e escopos em lista');
    $pdoTeste->tokens[$r['id']]['expira_em'] = date('Y-m-d H:i:s', time());
    apiCheck(ApiTokens::autenticar($r['token'], '') === null, 'Expiração inclusiva');
    $pdoTeste->tokens[$r['id']]['expira_em'] = null;
    ApiTokens::revogar($r['id'], $por);
    apiCheck(ApiTokens::autenticar($r['token'], '') === null, 'Token revogado recusado imediatamente');
    $n = count($pdoTeste->auditoria);
    ApiTokens::revogar($r['id'], $por);
    apiCheck(count($pdoTeste->auditoria) === $n && $pdoTeste->tokens[$r['id']]['revogado_por'] === $por, 'Revogação idempotente com ator');
    apiCheck(array_column($pdoTeste->auditoria, 'acao') === ['API_TOKEN_CRIAR', 'API_TOKEN_REVOGAR'], 'Auditoria de criação e revogação');
    apiCheck(!str_contains(json_encode($pdoTeste->auditoria), $r['token']) && !str_contains(json_encode($pdoTeste->auditoria), $row['hash']), 'Auditoria sem credenciais');
    $semValidade = ApiTokens::criar('Sem validade', ['cadastros'], null, $por);
    apiCheck($pdoTeste->tokens[$semValidade['id']]['expira_em'] === null && $semValidade['token'] !== $r['token'], 'Token único sem validade');
    foreach (['', str_repeat('n', 121), "Nome\ninválido"] as $nome) { apiRejeita(fn () => ApiTokens::criar($nome, ['acessos'], 30, $por)); }
    foreach ([[], ['outro'], [['acessos']], [1]] as $escopos) { apiRejeita(fn () => ApiTokens::criar('Teste', $escopos, 30, $por)); }
    foreach ([0, -1, 3651] as $dias) { apiRejeita(fn () => ApiTokens::criar('Teste', ['acessos'], $dias, $por)); }
    apiRejeita(fn () => ApiTokens::criar('Teste', ['acessos'], 30, 'invalido'));
    apiRejeita(fn () => ApiTokens::revogar('invalido', $por));
    $pdoTeste->falharAuditoria = true;
    $antes = $pdoTeste->tokens;
    try { ApiTokens::criar('Falha', ['acessos'], 30, $por); throw new LogicException('Deveria falhar'); }
    catch (RuntimeException $e) { apiCheck($e->getMessage() === 'Auditoria indisponível' && $antes === $pdoTeste->tokens, 'Rollback se criação não for auditada'); }
    try { ApiTokens::revogar($semValidade['id'], $por); throw new LogicException('Deveria falhar'); }
    catch (RuntimeException $e) { apiCheck($e->getMessage() === 'Auditoria indisponível' && $antes === $pdoTeste->tokens, 'Rollback se revogação não for auditada'); }
    $pdoTeste->falharAuditoria = false;
    for ($i = 0; $i < 120; $i++) {
        apiCheck(!\App\Core\RateLimiter::tooMany('api:v1:teste', 120, 1), 'Primeiras 120 consultas permitidas');
        \App\Core\RateLimiter::hit('api:v1:teste');
    }
    apiCheck(\App\Core\RateLimiter::tooMany('api:v1:teste', 120, 1), 'Consulta 121 bloqueada');
} finally { $prop->setValue(null, $anterior); }

// Servidor de teste limitado a localhost, sem banco real ou credenciais do ambiente.
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $erro);
if (!$socket) { throw new RuntimeException($erro); }
$porta = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$runtime = App::root('storage/cache');
if (!is_dir($runtime)) { mkdir($runtime, 0770, true); }
$trace = tempnam($runtime, 'api-trace-');
$log = tempnam($runtime, 'api-http-');
$env = getenv();
$env['API_TEST_TRACE'] = $trace;
$processo = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $porta, __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
if (!is_resource($processo)) { throw new RuntimeException('Não iniciou servidor HTTP'); }
fclose($pipes[0]);
function apiHttp(string $path, ?string $token = null, string $caso = '', string $metodo = 'GET'): array
{
    global $porta, $trace;
    $headers = ['X-Test-Case: ' . $caso];
    if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }
    $ch = curl_init('http://127.0.0.1:' . $porta . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 10]);
    $raw = curl_exec($ch);
    if ($raw === false) { throw new RuntimeException(curl_error($ch)); }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $tamanho = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $cabecalhos = substr($raw, 0, $tamanho);
    $body = substr($raw, $tamanho);
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $evidencia = json_decode((string) file_get_contents($trace), true, 512, JSON_THROW_ON_ERROR);
    apiCheck(str_contains(strtolower($cabecalhos), 'cache-control: no-store'), 'Sempre no-store');
    apiCheck(!str_contains(strtolower($cabecalhos), 'access-control-allow-origin:'), 'CORS desligado');
    apiCheck(str_contains(strtolower($cabecalhos), 'application/json; charset=utf-8'), 'JSON UTF-8');
    return [$status, $json, $cabecalhos, $evidencia];
}
try {
    $pronto = false;
    for ($i = 0; $i < 100; $i++) {
        $s = @stream_socket_client('tcp://127.0.0.1:' . $porta, $errno, $erro, 0.05);
        if ($s) { fclose($s); $pronto = true; break; }
        usleep(50000);
    }
    apiCheck($pronto, 'Servidor pronto');
    $token = 'sag_' . str_repeat('A', 40);
    [$status, $body, $headers] = apiHttp('/api/v1/status');
    apiCheck($status === 401 && isset($body['erro']) && str_contains($headers, 'WWW-Authenticate:'), '401 sem cabeçalho');
    apiCheck(apiHttp('/api/v1/status?token=' . $token)[0] === 401, 'Token na URL não autentica');
    apiCheck(apiHttp('/api/v1/status?token[]=' . $token)[0] === 401, 'Array de token na URL não autentica');
    apiCheck(apiHttp('/api/v1/status', 'sag_' . str_repeat('B', 40))[0] === 401, '401 inválido');
    foreach (['expirado', 'revogado'] as $caso) { apiCheck(apiHttp('/api/v1/status', $token, $caso)[0] === 401, '401 genérico por validade'); }
    [$status, $body, , $ev] = apiHttp('/api/v1/acessos', $token, 'sem-escopo');
    apiCheck($status === 403 && json_decode($ev['audit'][0]['detalhes'], true)['linhas'] === 0, '403 e auditoria sem dados');
    apiCheck(apiHttp('/api/v1/status', $token, 'sem-escopo')[0] === 200, 'Status dispensa escopo de dados');
    [$status, , $headers] = apiHttp('/api/v1/acessos', $token, 'limite');
    apiCheck($status === 429 && str_contains($headers, 'Retry-After: 60'), '429 orienta nova tentativa');
    [$status, $body] = apiHttp('/api/v1/status', $token);
    apiCheck($status === 200 && $body['dados'][0]['versao'] === 'v1' && count($body['dados'][0]['escopos']) === 5, 'Status e escopos');
    foreach (['acessos', 'presenca', 'solicitacoes', 'chamados', 'chamados/avaliacoes', 'cadastros/locais', 'cadastros/areas', 'cadastros/categorias', 'cadastros/pessoas-resumo'] as $recurso) {
        [$status, $body, $headers, $ev] = apiHttp('/api/v1/' . $recurso . '?por_pagina=2&token=ignorado&extra=ignorado', $token);
        apiCheck($status === 200 && count($body['dados']) === 2 && $body['total'] === 3 && $body['pagina'] === 1 && $body['por_pagina'] === 2, 'Envelope e primeira página: ' . $recurso);
        apiCheck(!str_contains(json_encode($body), 'SEGREDO-FICTICIO'), 'Nenhum campo proibido: ' . $recurso);
        apiCheck(!str_contains($body['proxima'], 'token') && !str_contains($body['proxima'], 'extra'), 'Próxima URL sem credenciais ou entrada arbitrária');
        apiCheck(!isset($body['dados'][0]['calendario_sla']) && !isset($body['dados'][0]['sla_resposta_snapshot']), 'Metadados internos de SLA removidos');
        $audit = $ev['audit'][0]; $detalhes = json_decode($audit['detalhes'], true);
        apiCheck($audit['acao'] === 'API_CONSULTA' && $detalhes['endpoint'] === '/api/v1/' . $recurso && $detalhes['linhas'] === 2, 'Auditoria de cada endpoint');
        apiCheck(!str_contains($audit['detalhes'], $token) && !str_contains($audit['detalhes'], 'Pessoa de teste'), 'Auditoria sem token ou dados');
        foreach ($body['dados'][0] as $key => $value) {
            if ($value !== null && in_array($key, ['created_at', 'updated_at', 'entrada_em', 'data_hora', 'prazo_resposta'], true)) {
                apiCheck((bool) preg_match('/T\d\d:\d\d:\d\d-04:00$/D', $value), 'Data ISO com fuso');
            }
        }
        if ($recurso === 'chamados') {
            apiCheck($body['dados'][0]['sla_resposta_status'] === 'no_prazo' && isset($body['dados'][0]['sla_solucao_status'], $body['dados'][0]['prazo_solucao_efetivo']), 'SLA reutiliza regra existente');
        }
        $path = parse_url($body['proxima'], PHP_URL_PATH) . '?' . parse_url($body['proxima'], PHP_URL_QUERY);
        [, $fim] = apiHttp($path, $token);
        apiCheck(count($fim['dados']) === 1 && $fim['proxima'] === null && $fim['pagina'] === 2, 'Última página');
    }
    foreach (['pagina=0', 'pagina=-1', 'pagina=1.2', 'pagina[]=1', 'por_pagina=5001', 'por_pagina=0', 'desde=2026-02-30', 'desde[]=2026-11-03', 'ate=ontem', 'alterado_desde=2026-11-03%27', 'desde=2026-11-04&ate=2026-11-03'] as $query) {
        apiCheck(apiHttp('/api/v1/acessos?' . $query, $token)[0] === 400, 'Filtro inválido recusado: ' . $query);
    }
    [$status, $body, , $ev] = apiHttp('/api/v1/acessos?desde=2026-11-01T00%3A00%3A00Z&ate=2026-11-03&alterado_desde=2026-11-02', $token);
    apiCheck($status === 200 && $body['por_pagina'] === 500, 'Filtros ISO aceitos e tamanho padrão');
    $consultas = array_values(array_filter($ev['sql'], static fn ($r) => str_contains($r[0], 'FROM registros_acesso')));
    apiCheck($consultas[0][1] === ['2026-10-31 20:00:00.000000', '2026-11-04 00:00:00.000000', '2026-11-02 00:00:00.000000'], 'Conversão de fuso e dia final inclusivo em parâmetros preparados');
    apiCheck(str_contains($consultas[0][0], 'r.data_hora >= ?') && str_contains($consultas[0][0], 'r.data_hora < ?') && str_contains($consultas[0][0], 'GREATEST('), 'Filtros temporal e incremental aplicados ao COUNT');
    apiCheck($consultas[0][1] === $consultas[1][1] && str_contains($consultas[1][0], 'ORDER BY r.data_hora, r.seq'), 'Contagem e página usam os mesmos filtros e desempate');
    apiCheck(apiHttp('/api/v1/acessos?por_pagina=5000', $token)[0] === 200, 'Máximo de 5000 aceito');
    [, $body] = apiHttp('/api/v1/acessos?pagina=99', $token);
    apiCheck($body['dados'] === [] && $body['proxima'] === null, 'Página após o fim é vazia');
    [$status, $body] = apiHttp('/api/v1/acessos', $token, 'erro');
    apiCheck($status === 500 && !str_contains(json_encode($body), 'Falha simulada'), 'Falhas não expõem SQL/dados');
    foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $metodo) {
        [$status, , $headers] = apiHttp('/api/v1/acessos', $token, '', $metodo);
        apiCheck($status === 405 && str_contains($headers, 'Allow: GET'), 'Método sem escrita/CSRF e JSON: ' . $metodo);
    }
    apiCheck(apiHttp('/api/v1/inexistente', $token)[0] === 404, 'Rota desconhecida responde JSON');
    // O contrato exige validar estes dois pontos no front controller, além do controller isolado.
    [$status, , $headers, $ev] = apiHttp('/api/v1/status?lang=en', $token);
    apiCheck(!str_contains(strtolower($headers), 'set-cookie:'), 'API não deve emitir cookies; confira bootstrap e public/index.php');
    apiCheck($ev['session'] === PHP_SESSION_NONE, 'API não deve iniciar sessão PHP');
} finally {
    proc_terminate($processo); proc_close($processo);
    @unlink($trace); @unlink($log);
}

echo "API BI: {$checks} verificações OK (PDO simulado e HTTP real; MariaDB não homologado).\n";

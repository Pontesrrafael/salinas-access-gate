<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{App, Auth, View};
use App\Services\{Chamados, FormularioDinamico, Upload};

// Subprocesso: percorre o roteador e os controladores reais com sessão fictícia de colaborador.
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--rbac-rota') {
    $router = new \App\Core\Router();
    require dirname(__DIR__) . '/app/routes.php';
    $rotas = (new ReflectionProperty($router, 'routes'))->getValue($router);
    [$metodo, $padrao] = $rotas[(int) $argv[2]];
    $path = preg_replace('/\{[a-z_]+\}/', '11111111-1111-4111-8111-111111111111', $padrao);
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_METHOD'] = $metodo;
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_POST['_csrf'] = \App\Core\Csrf::token();
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, ['id' => 'fixture-colaborador', 'papel' => 'colaborador', 'ativo' => 1, 'must_change_password' => 0, 'auth_origem' => 'local', 'seguranca_patrimonial' => 1]);
    register_shutdown_function(static function (): void { echo "\nRBAC_STATUS=" . http_response_code(); });
    $router->dispatch($metodo, $path);
    exit;
}

/** Dados fictícios para testar o HTML real sem MariaDB nem contas reais. */
function chamadoFixture(): array
{
    return [
        'id' => 'e4ef6cc8-aafe-4ad4-80be-a2420a856810', 'numero' => 42,
        'area_id' => 'e4ef6cc8-aafe-4ad4-80be-a2420a856801', 'categoria_id' => 'e4ef6cc8-aafe-4ad4-80be-a2420a856820',
        'titulo' => 'Manutenção da iluminação <script>alert(1)</script>', 'descricao' => str_repeat('Descrição longa sem espaços ', 8),
        'status' => 'pendente', 'solicitante_id' => 'fixture-admin', 'solicitante_nome' => 'Pessoa de teste',
        'atendente_id' => null, 'atendente_nome' => null, 'beneficiario_nome' => null, 'recorrente' => 1,
        'urgencia' => 2, 'impacto' => 3, 'prioridade' => 2,
        'created_at' => '2026-10-28 08:00:00', 'prazo_resposta' => '2026-10-28 09:00:00',
        'prazo_solucao' => '2026-10-28 18:00:00', 'pendente_desde' => '2026-10-28 10:00:00',
        'segundos_pendente' => 0, 'respondido_em' => null, 'solucionado_em' => null, 'fechado_em' => null,
        'area_nome' => 'Facilities', 'categoria_nome' => 'Manutenção',
    ];
}

/** Dublê de persistência: executa o controller e permite falhar após a gravação física. */
final class ChamadoTestePdo extends PDO
{
    public array $consultas = [];
    public bool $transacao = false;
    public bool $revertido = false;
    public function __construct(public Closure $responder) {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new ChamadoTesteStmt($this, $query); }
    public function beginTransaction(): bool { $this->transacao = true; return true; }
    public function inTransaction(): bool { return $this->transacao; }
    public function commit(): bool { $this->transacao = false; return true; }
    public function rollBack(): bool { $this->revertido = true; $this->transacao = false; return true; }
}
final class ChamadoTesteStmt extends PDOStatement
{
    private array $rows = [];
    public function __construct(private ChamadoTestePdo $db, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->db->consultas[] = [$this->sql, $params ?? []];
        $this->rows = ($this->db->responder)($this->sql, $params ?? []);
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows ? array_values($this->rows[0])[$column] : false; }
    public function rowCount(): int { return 1; }
}

function chamadoViewFixture(string $view, bool $atende = true, string $status = 'pendente'): array
{
    $c = chamadoFixture();
    $c['status'] = $status;
    $c['fechado_automaticamente'] = $status === 'fechado' ? 1 : 0;
    $u = ['id' => 'fixture-admin', 'papel' => 'admin', 'nome' => 'Pessoa de teste'];
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, $u);
    $areas = [['id' => $c['area_id'], 'nome' => 'Facilities']];
    $categorias = [['id' => $c['categoria_id'], 'nome' => 'Manutenção', 'parent_id' => null, 'caminho' => 'Infraestrutura › Manutenção', 'descricao' => 'Atendimento predial', 'urgencia_padrao' => 3, 'usar_horario_comercial' => 0]];
    $membros = [['id' => 'fixture-admin', 'nome' => 'Pessoa de teste']];
    return match ($view) {
        'abrir' => ['titulo' => t('Abrir chamado'), 'areas' => $areas, 'areaId' => $c['area_id'], 'categorias' => $categorias, 'categoriaId' => $c['categoria_id'], 'categoria' => $categorias[0], 'campos' => [], 'old' => []],
        'index' => ['titulo' => t('Fila de atendimento'), 'lista' => [$c], 'filtros' => array_fill_keys(['area_id', 'categoria_id', 'status', 'prioridade', 'atendente_id', 'prazo'], ''), 'fila' => true, 'areas' => $areas, 'categorias' => $categorias, 'membros' => $membros, 'total' => 1, 'pagina' => 1, 'limite' => 50, 'satisfacaoAreas' => [['nome' => 'Facilities', 'media' => 4.25, 'avaliacoes' => 4], ['nome' => 'SSMA', 'media' => null, 'avaliacoes' => 0]]],
        default => ['titulo' => t('Chamado #{numero}', ['numero' => 42]), 'c' => $c, 'u' => $u, 'atende' => $atende, 'eventos' => [
            ['id' => 'ev-1', 'tipo' => 'comentario', 'texto' => '<img src=x onerror=alert(1)>', 'usuario_nome' => 'Pessoa', 'created_at' => $c['created_at']],
            ...($atende ? [['id' => 'ev-2', 'tipo' => 'interno', 'texto' => 'Nota reservada de teste', 'usuario_nome' => 'Pessoa', 'created_at' => $c['created_at']]] : []),
        ], 'anexos' => [['id' => 'fixture-anexo', 'nome_arquivo' => str_repeat('arquivo', 20) . '.pdf', 'tamanho_bytes' => 1024, 'acompanhamento_id' => null]], 'respostas' => [['rotulo' => 'Local <teste>', 'valor' => '<b>Bloco A</b>']], 'membros' => $membros, 'old' => []],
    };
}

// Roteador apenas no servidor de teste, em loopback. Nunca habilitado na aplicação.
// php -S 127.0.0.1:8249 -t public tests/chamados.php
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($path, '/assets/')) { return false; }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); return; }
    \App\Core\Http::securityHeaders();
    if (($_GET['view'] ?? '') === 'configuracoes') {
        View::render('configuracoes/index', configuracoesViewFixture());
        return;
    }
    if (($_GET['view'] ?? '') === 'servicos-config') {
        View::render('servicos-config/index', servicosConfigViewFixture());
        return;
    }
    $view = in_array($_GET['view'] ?? '', ['abrir', 'index', 'detalhe'], true) ? $_GET['view'] : 'detalhe';
    $status = ($_GET['status'] ?? '') === 'fechado' ? 'fechado' : 'pendente';
    View::render('chamados/' . $view, chamadoViewFixture($view, true, $status));
    return;
}

/** Configurações fictícias para verificar o bloco de chamados sem consultar o AD ou o banco. */
function configuracoesViewFixture(): array
{
    chamadoViewFixture('index');
    return [
        'titulo' => t('Configurações'), 'cfg' => [], 'areas' => [], 'usuarios' => [],
        'linkPublico' => 'http://127.0.0.1/solicitar-acesso',
        'ldap' => ['enabled' => false, 'host' => '', 'base_dn' => '', 'bind_dn' => '', 'starttls' => true, 'tls_verify' => true, 'auto_provision' => false, 'sync_roles' => false],
        'ldapExt' => false, 'ldapTeste' => null, 'ldapUsuarios' => 0,
    ];
}

/** Cadastro e edição de SLAs com herança para inspeção do HTML e do navegador. */
function servicosConfigViewFixture(): array
{
    chamadoViewFixture('index');
    $area = ['id' => 'area-fixture', 'nome' => 'Facilities', 'slug' => 'facilities', 'descricao' => '', 'cor' => '#024B37', 'ativo' => 1,
        'sla_resposta_min' => 30, 'sla_solucao_min' => 120, 'usar_horario_comercial' => 0];
    $categoria = ['id' => 'categoria-fixture', 'nome' => 'Manutenção', 'parent_id' => null, 'ativo' => 1,
        'sla_resposta_min' => null, 'sla_solucao_min' => null, 'usar_horario_comercial' => null];
    return ['titulo' => t('Áreas e formulários'), 'areas' => [$area], 'areaAtual' => $area, 'usuarios' => [], 'membros' => [],
        'categorias' => [$categoria], 'campos' => [], 'editarArea' => $area['id'], 'editarCategoria' => '', 'editarCampo' => ''];
}

$checks = 0;
function expect(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
function rejeita(callable $fn, string $label): void
{
    try { $fn(); } catch (InvalidArgumentException) { expect(true, $label); return; }
    throw new RuntimeException($label);
}

$inicio = new DateTimeImmutable('2026-10-28 08:00:00');
expect(Chamados::prioridade(1, 4) === 1, 'Risco crítico tem prioridade máxima.');
expect(Chamados::prioridade(5, 1) === 6, 'Menor urgência/impacto tem prioridade mínima.');
for ($urgencia = 1; $urgencia <= 5; $urgencia++) {
    for ($impacto = 1; $impacto <= 4; $impacto++) {
        $p = Chamados::prioridade($urgencia, $impacto);
        expect($p >= 1 && $p <= 6, 'Matriz cobre todos os pares.');
        if ($impacto < 4) { expect(Chamados::prioridade($urgencia, $impacto + 1) <= $p, 'Mais impacto nunca reduz prioridade.'); }
        if ($urgencia < 5) { expect(Chamados::prioridade($urgencia + 1, $impacto) >= $p, 'Menos urgência nunca aumenta prioridade.'); }
    }
}
foreach ([[0, 1], [6, 1], [1, 0], [1, 5]] as [$u, $i]) { rejeita(fn () => Chamados::prioridade($u, $i), 'Faixas inválidas rejeitadas.'); }
expect(Chamados::prazo($inicio, null) === null, 'SLA não definido fica nulo.');
expect(Chamados::prazo($inicio, 1440)?->format('Y-m-d H:i:s') === '2026-10-29 08:00:00', 'SLA em horas corridas.');
rejeita(fn () => Chamados::prazo($inicio, -1), 'SLA negativo recusado.');
rejeita(fn () => Chamados::prazo($inicio, 0), 'SLA zero recusado.');
$prazo = $inicio->modify('+100 minutes');
expect(Chamados::situacaoPrazo($inicio, $prazo, $inicio->modify('+79 minutes')) === 'no_prazo', 'Antes da janela de 20%.');
expect(Chamados::situacaoPrazo($inicio, $prazo, $inicio->modify('+80 minutes')) === 'vencendo', 'Exatamente 20% restante.');
expect(Chamados::situacaoPrazo($inicio, $prazo, $prazo) === 'vencendo', 'No instante limite.');
expect(Chamados::situacaoPrazo($inicio, $prazo, $prazo->modify('+1 second')) === 'vencido', 'Vencido um segundo depois.');
expect(Chamados::situacaoPrazo($inicio, null, $inicio) === 'no_prazo', 'Sem SLA não vence.');

$c = chamadoFixture();
$agora = new DateTimeImmutable('2026-10-28 12:00:00');
expect(Chamados::prazoSolucao($c, $agora)?->format('H:i') === '20:00', 'Prazo efetivo soma pausa em andamento.');
$mudancas = Chamados::mudarStatus($c, 'atribuido', $agora);
expect($mudancas['segundos_pendente'] === 7200 && $mudancas['pendente_desde'] === null, 'Saída de pendente acumula segundos.');
$c = array_replace($c, $mudancas);
expect(Chamados::prazoSolucao($c, $agora->modify('+1 hour'))?->format('H:i') === '20:00', 'Pausa não é contada duas vezes.');
$c = array_replace($c, Chamados::mudarStatus($c, 'pendente', $agora));
$c = array_replace($c, Chamados::mudarStatus($c, 'solucionado', $agora->modify('+1 hour')));
expect($c['segundos_pendente'] === 10800 && $c['solucionado_em'] === '2026-10-28 13:00:00', 'Múltiplas pausas e solução durante pausa.');
expect(Chamados::situacaoSolucao($c, $agora->modify('+10 days')) === 'no_prazo', 'Solução encerra o relógio.');
expect(!Chamados::podeFecharAutomaticamente($c, new DateTimeImmutable('2026-11-04 12:59:59')), 'Auto fechamento espera sete dias.');
expect(Chamados::podeFecharAutomaticamente($c, new DateTimeImmutable('2026-11-04 13:00:00')), 'Auto fechamento no limite.');
expect(!Chamados::podeFecharAutomaticamente($c, $agora->modify('+30 days'), 0), 'Configuração zero desliga auto fechamento.');
$reaberto = array_replace($c, Chamados::mudarStatus($c, 'atribuido', $agora->modify('+1 day')));
expect($reaberto['solucionado_em'] === null && $reaberto['fechado_em'] === null && $reaberto['segundos_pendente'] === 10800, 'Reabrir preserva pausas e limpa encerramento.');
expect(!Chamados::podeFecharAutomaticamente($reaberto, $agora->modify('+30 days')), 'Reaberto não fecha automaticamente.');
$pendente = chamadoFixture();
$pendente['prazo_solucao'] = '2026-10-28 18:00:00';
$pendente['pendente_desde'] = '2026-10-28 16:00:00';
expect(Chamados::situacaoSolucao($pendente, new DateTimeImmutable('2026-11-01')) === 'vencendo', 'Pausa não altera a janela original de 20%.');
foreach (array_keys(Chamados::STATUS) as $origem) {
    foreach (array_keys(Chamados::STATUS) as $destino) {
        $transicao = Chamados::podeTransicionar($origem, $destino);
        if ($origem === $destino || $origem === 'fechado' || ($destino === 'fechado' && $origem !== 'solucionado')) { expect(!$transicao, 'Transição proibida: ' . $origem . ' → ' . $destino); }
    }
}
foreach (['novo', 'atribuido', 'planejado', 'pendente'] as $status) { expect(Chamados::podeTransicionar($status, 'solucionado'), 'Status ativo aceita solução.'); }
rejeita(fn () => Chamados::mudarStatus(['status' => 'fechado'], 'atribuido', $inicio), 'Fechado é terminal.');
$arvore = Chamados::categoriasEmArvore([['id' => 'b', 'nome' => 'Lâmpadas', 'parent_id' => 'a'], ['id' => 'a', 'nome' => 'Elétrica', 'parent_id' => null]]);
expect($arvore[1]['caminho'] === 'Elétrica › Lâmpadas', 'Select mostra a hierarquia completa.');
expect(count(Chamados::categoriasEmArvore([['id' => 'a', 'nome' => 'A', 'parent_id' => 'b'], ['id' => 'b', 'nome' => 'B', 'parent_id' => 'a']])) === 2, 'Ciclo legado não trava a árvore.');

// Catálogos também cobrem os rótulos dinâmicos que o inventário literal não encontra.
foreach (['en', 'es'] as $locale) {
    $catalog = json_decode(file_get_contents(App::root('app/Lang/' . $locale . '.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach ([Chamados::STATUS, Chamados::URGENCIAS, Chamados::EXPLICACOES, Chamados::IMPACTOS, Chamados::PRIORIDADES, Chamados::PRAZOS, Chamados::TIPOS] as $labels) {
        foreach ($labels as $label) { expect(isset($catalog[$label]), $locale . ' traduz ' . $label); }
    }
    foreach (['Serviços', 'Abrir chamado', 'Meus chamados', 'Fila de atendimento'] as $label) { expect(isset($catalog[$label]), 'Menu traduzido: ' . $label); }
}
foreach (['abrir', 'index', 'detalhe'] as $view) {
    $html = View::partial('chamados/' . $view, chamadoViewFixture($view));
    expect(!preg_match('/<(script|style)\b|\s(?:style|onchange|onclick)=/i', $html), 'View compatível com CSP: ' . $view);
    expect(!str_contains($html, '<script>alert(1)</script>') && !str_contains($html, '<img src=x'), 'Dados escapados: ' . $view);
    if ($view === 'detalhe') { expect(str_contains($html, 'Nota reservada de teste'), 'Equipe vê nota interna.'); }
}
$html = View::partial('chamados/detalhe', chamadoViewFixture('detalhe', false));
expect(!str_contains($html, 'Nota reservada de teste') && !str_contains($html, 'value="interno"'), 'Solicitante não recebe nota interna nem controle para criá-la.');
expect(FormularioDinamico::validar([], [], [])['erros'] === [], 'Área sem campos personalizados não gera erro.');
expect(FormularioDinamico::validar([['id' => 'x', 'rotulo' => 'Local', 'tipo' => 'texto', 'obrigatorio' => 1, 'opcoes' => []]], [], [])['erros'] !== [], 'Campo obrigatório vazio é recusado.');

// Arquivos sintéticos locais; não consulta nem modifica arquivos do usuário.
$dir = App::root('storage');
$tmp = tempnam($dir, 'chamados-test-');
try {
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    $file = ['name' => 'teste.pdf', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK];
    expect(Upload::validar([$file], true) === [], 'PDF permitido em chamado.');
    expect(Upload::validar([$file]) !== [], 'PDF continua proibido na portaria.');
    file_put_contents($tmp, str_repeat('x', 4 * 1024 * 1024 + 1));
    expect(Upload::validar([$file], true) !== [], 'Tamanho real acima de 4 MB é recusado mesmo com tamanho declarado menor.');
    file_put_contents($tmp, '<?php echo 1;');
    expect(Upload::validar([$file], true) !== [], 'Arquivo executável disfarçado é recusado.');
    expect(Upload::caminho('../../app/bootstrap.php') === null, 'Download bloqueia path traversal.');
} finally { if (is_file($tmp)) { unlink($tmp); } }

// Calendário puro, sem consultar configurações nem alterar SLAs antigos.
$cal = Chamados::calendario(['dias' => '1,2,3,4,5', 'inicio' => '07:00', 'fim' => '17:00', 'feriados' => "2026-11-02\n2026-11-02"]);
expect($cal['feriados'] === ['2026-11-02'], 'Feriados duplicados são normalizados.');
foreach ([
    ['2026-10-28 16:30:00', 90, '2026-10-29 08:00:00'],
    ['2026-10-30 16:30:00', 90, '2026-11-03 08:00:00'],
    ['2026-10-31 12:00:00', 60, '2026-11-03 08:00:00'],
    ['2026-10-28 05:00:00', 60, '2026-10-28 08:00:00'],
    ['2026-10-28 17:00:00', 60, '2026-10-29 08:00:00'],
    ['2026-10-28 16:00:00', 60, '2026-10-28 17:00:00'],
    ['2026-10-28 16:59:30', 1, '2026-10-29 07:00:30'],
] as [$data, $minutos, $esperado]) {
    expect(Chamados::prazo(new DateTimeImmutable($data), $minutos, $cal)?->format('Y-m-d H:i:s') === $esperado, 'SLA útil: ' . $data);
}
$semFeriado = array_replace($cal, ['feriados' => []]);
expect(Chamados::prazo(new DateTimeImmutable('2026-10-30 16:30'), 90, $semFeriado)?->format('Y-m-d H:i') === '2026-11-02 08:00', 'Fim de semana sem feriado.');
expect(Chamados::prazo(new DateTimeImmutable('2026-10-31 12:00'), 60)?->format('Y-m-d H:i') === '2026-10-31 13:00', 'Categoria 24x7 ignora dias sem expediente.');
foreach ([['dias' => []], ['dias' => [0]], ['dias' => ['2x']], ['inicio' => '17:00'], ['inicio' => '22:00', 'fim' => '06:00'], ['fim' => '25:00'], ['feriados' => '2026-02-30'], ['feriados' => '01/01/2026']] as $invalido) {
    rejeita(fn () => Chamados::calendario(array_replace($cal, $invalido)), 'Calendário inválido rejeitado.');
}
$sexta = new DateTimeImmutable('2026-10-30 16:00');
$terca = new DateTimeImmutable('2026-11-03 08:00');
expect(Chamados::segundosUteis($sexta, $terca, $cal) === 7200, 'Conta só expediente em intervalo com feriado.');
expect(Chamados::segundosUteis($terca, $sexta, $cal) === -7200, 'Intervalo invertido preserva o sinal.');
$c = array_replace(chamadoFixture(), [
    'created_at' => '2026-10-30 16:00:00', 'prazo_solucao' => '2026-11-03 09:00:00',
    'pendente_desde' => '2026-10-30 16:30:00', 'calendario_sla' => json_encode($cal),
]);
$agora = new DateTimeImmutable('2026-11-03 08:30');
expect(Chamados::prazoSolucao($c, $agora)?->format('Y-m-d H:i:s') === '2026-11-03 11:00:00', 'Pausa soma somente segundos úteis.');
$retomado = array_replace($c, Chamados::mudarStatus($c, 'atribuido', $agora));
expect($retomado['segundos_pendente'] === 7200, 'Saída da pausa persiste segundos úteis.');
expect(Chamados::prazoSolucao($retomado, $agora)?->format('Y-m-d H:i:s') === '2026-11-03 11:00:00', 'Pausa útil não conta duas vezes.');
expect(Chamados::situacaoSolucao($c, $agora) === Chamados::situacaoSolucao($c, $agora->modify('+10 days')), 'Selo permanece congelado durante a pausa útil.');
$limite = new DateTimeImmutable('2026-11-03 11:00');
expect(Chamados::situacaoSolucao($retomado, $limite->modify('+1 second')) === 'vencido', 'SLA útil vence um segundo após o limite.');
expect(Chamados::situacaoSolucao($retomado, new DateTimeImmutable('2026-11-03 10:24')) === 'vencendo', 'Janela de 20% usa orçamento de três horas úteis.');
expect(Chamados::situacaoSolucao($retomado, new DateTimeImmutable('2026-11-03 10:23:59')) === 'no_prazo', 'Segundo anterior à janela útil.');
$vencido = array_replace($c, ['pendente_desde' => '2026-11-03 10:00:00']);
expect(Chamados::situacaoSolucao($vencido, new DateTimeImmutable('2026-11-10 10:00')) === 'vencido', 'Pausa não recupera SLA já vencido.');
$semExpediente = array_replace($c, ['pendente_desde' => '2026-10-30 18:00:00']);
expect(Chamados::prazoSolucao($semExpediente, new DateTimeImmutable('2026-11-02 12:00'))?->format('Y-m-d H:i:s') === '2026-11-03 09:00:00', 'Pausa fora do expediente não estende SLA.');

$fechado = array_replace(chamadoFixture(), ['status' => 'fechado', 'anonimizado_em' => null]);
expect(Chamados::podeAvaliar($fechado, $fechado['solicitante_id'], false), 'Solicitante pode avaliar fechado.');
expect(!Chamados::podeAvaliar($fechado, 'outra-conta', false), 'Equipe e outros usuários não avaliam em nome do solicitante.');
expect(!Chamados::podeAvaliar($fechado, $fechado['solicitante_id'], true), 'Segunda avaliação é recusada.');
expect(!Chamados::podeAvaliar(chamadoFixture(), $fechado['solicitante_id'], false), 'Chamado ativo não recebe avaliação.');
expect(!Chamados::podeAvaliar(array_replace($fechado, ['anonimizado_em' => '2026-10-28']), $fechado['solicitante_id'], false), 'Chamado retido não recebe novo texto pessoal.');
for ($nota = 1; $nota <= 5; $nota++) { expect(Chamados::validarSatisfacao((string) $nota, ' ') === ['nota' => $nota, 'comentario' => null], 'Notas válidas e comentário opcional.'); }
foreach ([0, 6, '1.5', 'x', null, [], true] as $nota) { rejeita(fn () => Chamados::validarSatisfacao($nota, ''), 'Nota inválida recusada.'); }
rejeita(fn () => Chamados::validarSatisfacao(5, str_repeat('á', 2001)), 'Limite do comentário medido em caracteres.');
expect(Chamados::validarSatisfacao(5, str_repeat('á', 2000))['nota'] === 5, 'Comentário no limite aceito.');
$html = View::partial('chamados/detalhe', chamadoViewFixture('detalhe', false, 'fechado'));
expect(substr_count($html, 'type="radio"') === 5 && str_contains($html, 'value="avaliar"'), 'Avaliação funciona por POST sem JavaScript.');
$fixture = chamadoViewFixture('detalhe', true, 'fechado');
$fixture['satisfacao'] = ['nota' => 5, 'comentario' => '<script>alert(1)</script>', 'created_at' => '2026-10-28 12:00'];
$html = View::partial('chamados/detalhe', $fixture);
expect(!str_contains($html, 'type="radio"') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Nota existente exibida com texto escapado, sem novo formulário.');
$fixture = chamadoViewFixture('detalhe', true, 'fechado');
$fixture['u']['id'] = 'outro-atendente';
expect(!str_contains(View::partial('chamados/detalhe', $fixture), 'type="radio"'), 'Atendente não recebe controles da pesquisa de outro solicitante.');
expect(!Chamados::podeFecharAutomaticamente($fechado, new DateTimeImmutable('2030-01-01')), 'Fechamento automático não repete fechamento.');
$solucionado = array_replace($fechado, ['status' => 'solucionado', 'solucionado_em' => '2026-10-28 10:00']);
expect(!Chamados::podeFecharAutomaticamente($solucionado, new DateTimeImmutable('2026-10-30 09:59:59'), 2), 'Prazo personalizado respeita o segundo anterior.');
expect(Chamados::podeFecharAutomaticamente($solucionado, new DateTimeImmutable('2026-10-30 10:00'), 2), 'Prazo personalizado fecha no limite.');
expect(!Chamados::podeFecharAutomaticamente(array_replace($solucionado, ['solucionado_em' => null]), new DateTimeImmutable('2030-01-01')), 'Sem data da solução não fecha.');
$html = View::partial('configuracoes/index', configuracoesViewFixture());
expect(substr_count($html, 'name="horario_dias[]"') === 7, 'Configuração exibe os sete dias ISO.');
expect(str_contains($html, 'name="retencao_chamados_meses" min="6"') && str_contains($html, 'name="email_ativo"'), 'Configurações de retenção e notificações disponíveis.');
expect(!preg_match('/<(script|style)\b|\s(?:style|onchange|onclick)=/i', $html), 'Novas configurações compatíveis com CSP.');


// Escala única e transferência: datas da abertura e resposta anterior continuam mensuráveis.
expect(Chamados::URGENCIAS[1] === 'Muito alta' && Chamados::URGENCIAS[5] === 'Muito baixa', 'Escala de urgência coincide com a prioridade.');
expect(Chamados::rotulosUrgencia()[1] === '1 — Muito alta', 'Configuração recebe rótulo da escala única.');
$aceite = Chamados::mudancasAtribuicao(array_replace(chamadoFixture(), ['status' => 'novo']), 'novo-atendente', $agora);
expect($aceite['respondido_em'] === $agora->format('Y-m-d H:i:s') && $aceite['status'] === 'atribuido', 'Atribuição inicial registra aceite e primeira resposta.');
$jaRespondido = array_replace(chamadoFixture(), ['status' => 'atribuido', 'respondido_em' => '2026-10-28 08:10:00', 'atendente_id' => 'antigo']);
expect(!isset(Chamados::mudancasAtribuicao($jaRespondido, 'novo-atendente', $agora)['respondido_em']), 'Reatribuição não substitui resposta anterior.');
expect(Chamados::mudancasAtribuicao($jaRespondido, 'antigo', $agora) === [], 'Atribuição repetida é idempotente.');
expect(isset(Chamados::mudancasAtribuicao(array_replace($jaRespondido, ['respondido_em' => null]), 'antigo', $agora)['respondido_em']), 'Aceite corrige primeira resposta ausente mesmo para o responsável atual.');
$categoriaDestino = ['id' => 'destino', 'area_id' => 'nova-area', 'sla_resposta_min' => 15, 'sla_solucao_min' => 60, 'usar_horario_comercial' => 0];
$base = array_replace(chamadoFixture(), ['status' => 'atribuido', 'respondido_em' => null]);
$nova = Chamados::transferir($base, $categoriaDestino, $agora);
expect($nova['status'] === 'novo' && $nova['atendente_id'] === null && $nova['area_id'] === 'nova-area', 'Transferência devolve à triagem do destino.');
expect($nova['prazo_resposta'] === $agora->modify('+15 minutes')->format('Y-m-d H:i:s') && $nova['prazo_solucao'] === $agora->modify('+60 minutes')->format('Y-m-d H:i:s'), 'Transferência aplica prazos novos a partir da operação.');
expect($nova['segundos_pendente'] === 0 && $nova['pendente_desde'] === null && !isset($nova['created_at']), 'Pausa anterior não contamina o novo SLA; abertura preservada.');
expect(Chamados::situacaoSolucao(array_replace($base, $nova), $agora->modify('+49 minutes')) === 'vencendo', 'Janela de alerta usa a base da transferência.');
$respondido = array_replace($base, ['respondido_em' => '2026-10-28 08:30:00']);
$transferido = array_replace($respondido, Chamados::transferir($respondido, $categoriaDestino, $agora));
expect($transferido['respondido_em'] === $respondido['respondido_em'] && $transferido['prazo_resposta'] === $respondido['prazo_resposta'], 'Transferir preserva primeira resposta atendida.');
expect(Chamados::situacaoResposta($transferido, $agora) === Chamados::situacaoResposta($respondido, $agora), 'Transferir preserva a medição da primeira resposta anterior.');
rejeita(fn () => Chamados::transferir(array_replace($base, ['status' => 'fechado']), $categoriaDestino, $agora), 'Fechado não pode ser transferido.');
expect(Chamados::textoSla($categoriaDestino) === 'SLA em horas corridas (24x7).', 'Regime 24x7 comunicado conforme categoria.');
expect(!Chamados::podeFecharAutomaticamente(array_replace($solucionado, ['ultimo_comentario_solicitante' => '2026-10-29 10:00:00']), new DateTimeImmutable('2030-01-01')), 'Interação após solução bloqueia encerramento automático.');
expect(Chamados::podeFecharAutomaticamente(array_replace($solucionado, ['ultimo_comentario_solicitante' => '2026-10-27 10:00:00']), new DateTimeImmutable('2030-01-01')), 'Interação anterior à solução não bloqueia encerramento.');

$fixture = chamadoViewFixture('detalhe', false, 'solucionado');
$fixture['old'] = ['status_texto' => 'Preciso de nova análise'];
$html = View::partial('chamados/detalhe', $fixture);
expect(str_contains($html, 'value="fechado"') && str_contains($html, 'name="status_texto"') && !str_contains($html, 'name="acompanhamento_texto"'), 'Solicitante aprova ou reabre sem acompanhamento duplicado.');
expect(substr_count($html, 'Preciso de nova análise') === 1, 'Recuperação do motivo não aparece em outros formulários.');
$fixture = chamadoViewFixture('detalhe', false, 'fechado');
$fixture['old'] = ['avaliacao_nota' => '4', 'avaliacao_comentario' => 'Boa resposta'];
$html = View::partial('chamados/detalhe', $fixture);
expect(str_contains($html, 'value="4" checked') && str_contains($html, '>Boa resposta</textarea>'), 'Avaliação mantém nota e comentário quando há erro.');
$fixture = chamadoViewFixture('detalhe');
$fixture['membros'] = [];
$html = View::partial('chamados/detalhe', $fixture);
expect(str_contains($html, 'name="a_mim"'), 'Admin pode assumir mesmo fora da equipe.');
$fixture['u']['id'] = 'outro-atendente';
$html = View::partial('chamados/detalhe', $fixture);
expect(str_contains($html, 'name="status_texto"') && str_contains($html, 'multipart/form-data'), 'Atualização de status aceita anexos.');
$fixture['anexos'][0]['campo_id'] = 'campo-anexo';
$fixture['respostas'] = [['campo_id' => 'campo-anexo', 'rotulo' => 'Comprovante', 'valor' => 'comprovante.pdf']];
$html = View::partial('chamados/detalhe', $fixture);
expect(str_contains($html, '/chamados/anexos/fixture-anexo') && substr_count($html, '/chamados/anexos/fixture-anexo') === 1, 'Arquivo dinâmico tem link autenticado no campo, sem duplicação.');
$html = View::partial('chamados/index', chamadoViewFixture('index'));
foreach (['name="q"', 'value="me"', 'value="none"', 'value="prioridade"', 'value="prazo"', 'Primeira resposta', 'Pessoa de teste'] as $texto) { expect(str_contains($html, $texto), 'Fila contém busca, triagem, ordenação, solicitante e resposta.'); }

// RBAC: mesmo um sinal legado de segurança patrimonial não amplia o novo perfil.
$salvo = Auth::user();
(new ReflectionProperty(Auth::class, 'user'))->setValue(null, ['id' => 'colaborador', 'papel' => 'colaborador', 'seguranca_patrimonial' => 1]);
(new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, false);
expect(Auth::can('chamados.abrir') && !Auth::can('chamados.atender'), 'Colaborador abre; atendimento exige associação.');
$matriz = (new ReflectionClass(Auth::class))->getConstant('PERMISSOES');
foreach (array_keys($matriz) as $permissao) {
    if ($permissao !== 'chamados.abrir') { expect(!Auth::can($permissao), 'Colaborador não recebe permissão legada: ' . $permissao); }
}
expect(!Auth::isSegurancaPatrimonial(), 'Colaborador ignora sinal residual de segurança patrimonial.');
(new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, true);
expect(Auth::can('chamados.atender'), 'Membro colaborador pode acessar atendimento, sujeito ao escopo por área.');
(new ReflectionProperty(Auth::class, 'user'))->setValue(null, $salvo);
(new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, null);
putenv('LDAP_GROUP_COLABORADOR=CN=Servicos,DC=teste');
expect(\App\Services\LdapUsuarios::papelPorGrupos(['cn=servicos,dc=teste']) === 'colaborador', 'LDAP aceita grupo explícito exclusivo de serviços.');
expect(\App\Services\LdapUsuarios::papelPorGrupos([]) === null, 'LDAP não concede acesso sem grupo autorizado.');
putenv('LDAP_GROUP_COLABORADOR');

$router = new \App\Core\Router();
require dirname(__DIR__) . '/app/routes.php';
$rotas = (new ReflectionProperty($router, 'routes'))->getValue($router);
$rotasLegadas = 0;
foreach ($rotas as $i => [$metodo, $padrao, $handler]) {
    if (!is_array($handler) || in_array($handler[0], [\App\Controllers\AuthController::class, \App\Controllers\MfaController::class, \App\Controllers\PublicoController::class, \App\Controllers\TutoriaisController::class, \App\Controllers\ChamadosController::class], true)) { continue; }
    $processo = proc_open([PHP_BINARY, __FILE__, '--rbac-rota', (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($processo)) { throw new RuntimeException('Não foi possível iniciar teste RBAC.'); }
    $saida = stream_get_contents($pipes[1]); $erro = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($processo);
    $esperado = $padrao === '/painel' ? 303 : 403;
    expect($exit === 0 && str_contains($saida, 'RBAC_STATUS=' . $esperado), "Rota legada {$metodo} {$padrao} protege colaborador: {$erro} {$saida}");
    $rotasLegadas++;
}
expect($rotasLegadas > 50, 'Cobertura passa pelas rotas legadas e configuração administrativa.');

$propPdo = new ReflectionProperty(\App\Core\Database::class, 'pdo');
$propSettings = new ReflectionProperty(\App\Services\Settings::class, 'cache');
$pdoAnterior = $propPdo->getValue(); $settingsAnteriores = $propSettings->getValue();
$usuarioAnterior = Auth::user(); $getAnterior = $_GET; $postAnterior = $_POST; $filesAnteriores = $_FILES;
$servidorAnterior = $_SERVER;
$tmp = tempnam(App::root('storage'), 'rollback-');
try {
    $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['REQUEST_URI'] = '/chamados';
    $propSettings->setValue(null, ['chamados_ativo' => '1', 'email_ativo' => '0']);
    $ator = ['id' => '11111111-1111-4111-8111-111111111111', 'nome' => 'Colaborador teste', 'papel' => 'colaborador', 'ativo' => 1, 'must_change_password' => 0, 'auth_origem' => 'local', 'email' => 'teste@example.test'];
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, $ator);
    (new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, true);
    $pdo = new ChamadoTestePdo(static fn ($sql, $params) => str_starts_with($sql, 'SELECT COUNT(*)') ? [[0]] : []);
    $propPdo->setValue(null, $pdo);
    foreach (['me', 'none'] as $atendente) {
        $_GET = ['q' => "x%' OR 1=1 --_", 'atendente_id' => $atendente, 'ordem' => 'prioridade']; $_POST = [];
        ob_start(); (new \App\Controllers\ChamadosController())->fila(); ob_end_clean();
        $consulta = current(array_filter($pdo->consultas, static fn ($q) => str_starts_with($q[0], 'SELECT c.*')));
        expect(str_contains($consulta[0], 'm.area_id = c.area_id AND m.usuario_id = ?') && in_array($ator['id'], $consulta[1], true), 'Consulta de fila mantém escopo do membro.');
        expect(!str_contains($consulta[0], "OR 1=1 --") && in_array("%x!%' OR 1=1 --!_%", $consulta[1], true), 'Busca parametrizada trata curingas como texto.');
        expect(str_contains($consulta[0], $atendente === 'none' ? 'c.atendente_id IS NULL' : 'c.atendente_id = ?') && str_contains($consulta[0], 'ORDER BY c.prioridade'), 'Atalho aplica filtro e ordenação no servidor.');
        $pdo->consultas = [];
    }
    $_GET = [];
    ob_start(); (new \App\Controllers\ChamadosController())->abrir(); ob_end_clean();
    $consulta = current(array_filter($pdo->consultas, static fn ($q) => str_starts_with($q[0], 'SELECT a.id, a.nome, a.descricao')));
    expect(str_contains($consulta[0], 'u.ativo = 1') && str_contains($consulta[0], 'c.ativo = 1') && str_contains($consulta[0], 'a.ativo = 1'), 'Publicação exige área, categoria e equipe ativas.');

    file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
    $base = chamadoFixture(); $campoId = '11111111-1111-4111-8111-111111111112';
    $categoria = ['id' => $base['categoria_id'], 'area_id' => $base['area_id'], 'sla_resposta_min' => null, 'sla_solucao_min' => null, 'usar_horario_comercial' => null];
    $metaAnexo = null;
    $chamadoGravado = null;
    $pdo = new ChamadoTestePdo(static function ($sql, $params) use ($categoria, $campoId, &$metaAnexo, &$chamadoGravado): array {
        if (str_starts_with($sql, 'SELECT c.* FROM servico_categorias')) { return [$categoria]; }
        if (str_starts_with($sql, 'SELECT * FROM servico_areas')) { return [['id' => $categoria['area_id'], 'sla_resposta_min' => 30, 'sla_solucao_min' => 120, 'usar_horario_comercial' => 0]]; }
        if (str_contains($sql, 'FROM servico_campos')) { return [['id' => $campoId, 'rotulo' => 'Comprovante', 'tipo' => 'anexo', 'opcoes' => null, 'obrigatorio' => 1, 'ativo' => 1]]; }
        if (str_starts_with($sql, 'INSERT INTO `chamado_anexos`')) {
            preg_match('/\((.*?)\) VALUES/', $sql, $m);
            $metaAnexo = array_combine(array_map(static fn ($k) => trim($k, ' `'), explode(',', $m[1])), $params);
            throw new RuntimeException('Falha ao gravar vínculo do anexo');
        }
        if (str_starts_with($sql, 'INSERT INTO `chamados`')) {
            preg_match('/\((.*?)\) VALUES/', $sql, $m);
            $chamadoGravado = array_combine(array_map(static fn ($k) => trim($k, ' `'), explode(',', $m[1])), $params);
            return [];
        }
        if (str_starts_with($sql, 'INSERT INTO `chamado_respostas`')) { return []; }
        throw new RuntimeException('Consulta inesperada no rollback: ' . $sql);
    });
    $propPdo->setValue(null, $pdo);
    $_POST = ['area_id' => $base['area_id'], 'categoria_id' => $base['categoria_id'], 'titulo' => 'Chamado de teste', 'urgencia' => '2', 'impacto' => '3', 'para_voce' => '1'];
    $_FILES = ['campos' => ['name' => [$campoId => 'comprovante.pdf'], 'tmp_name' => [$campoId => $tmp], 'size' => [$campoId => filesize($tmp)], 'error' => [$campoId => UPLOAD_ERR_OK]]];
    try { (new \App\Controllers\ChamadosController())->criar(); throw new LogicException('Deveria falhar antes do redirect'); }
    catch (RuntimeException $e) { expect($e->getMessage() === 'Falha ao gravar vínculo do anexo', 'Controller atingiu persistência do arquivo dinâmico.'); }
    expect($pdo->revertido && $metaAnexo['campo_id'] === $campoId && $metaAnexo['mime_type'] === 'application/pdf', 'Anexo dinâmico é gravado com campo e MIME na transação do chamado.');
    expect(Upload::caminho($metaAnexo['storage_path']) === null, 'Rollback do controller remove arquivo físico já movido.');
    $abertura = new DateTimeImmutable($chamadoGravado['created_at']);
    expect($chamadoGravado['prazo_resposta'] === $abertura->modify('+30 minutes')->format('Y-m-d H:i:s')
        && $chamadoGravado['prazo_solucao'] === $abertura->modify('+120 minutes')->format('Y-m-d H:i:s')
        && $chamadoGravado['calendario_sla'] === null, 'Controller de criação grava prazos e regime herdados da área.');

    // Percorre também o controller de transferência para verificar a leitura da área de destino.
    $destinoId = '11111111-1111-4111-8111-111111111113';
    $mudancasTransferencia = null;
    $pdo = new ChamadoTestePdo(static function ($sql, $params) use ($base, $destinoId, &$mudancasTransferencia): array {
        if (str_starts_with($sql, 'SELECT * FROM chamados')) { return [$base]; }
        if (str_starts_with($sql, 'SELECT 1 FROM servico_area_membros')) { return [[1]]; }
        if (str_starts_with($sql, 'SELECT cat.*')) { return [['id' => $destinoId, 'area_id' => 'area-destino', 'nome' => 'Destino', 'area_nome' => 'Área de destino']]; }
        if (str_contains($sql, 'FROM chamado_respostas') || str_contains($sql, 'FROM servico_campos')) { return []; }
        if (str_starts_with($sql, 'SELECT a.nome')) { return [['area_nome' => 'Área de origem', 'nome' => 'Origem']]; }
        if (str_starts_with($sql, 'SELECT * FROM servico_areas')) {
            expect($params === ['area-destino'] && str_ends_with($sql, 'FOR UPDATE'), 'Controller bloqueia e consulta a área correta do destino.');
            return [['sla_resposta_min' => 45, 'sla_solucao_min' => 180, 'usar_horario_comercial' => 0]];
        }
        if (str_starts_with($sql, 'UPDATE `chamados`')) {
            preg_match_all('/`(\w+)` = \?/', $sql, $m);
            $mudancasTransferencia = array_combine($m[1], array_slice($params, 0, count($m[1])));
            throw new RuntimeException('Interromper transferência simulada após calcular SLA');
        }
        throw new RuntimeException('Consulta inesperada na transferência: ' . $sql);
    });
    $propPdo->setValue(null, $pdo);
    $_POST = ['acao' => 'transferir', 'destino_categoria_id' => $destinoId, 'transferencia_motivo' => 'Encaminhar à área responsável'];
    $_FILES = [];
    try { (new \App\Controllers\ChamadosController())->status($base['id']); throw new LogicException('Deveria interromper antes do redirect'); }
    catch (RuntimeException $e) { expect($e->getMessage() === 'Interromper transferência simulada após calcular SLA', 'Controller percorreu transferência até persistência.'); }
    $inicioTransferencia = new DateTimeImmutable($mudancasTransferencia['sla_inicio']);
    expect($mudancasTransferencia['prazo_resposta'] === $inicioTransferencia->modify('+45 minutes')->format('Y-m-d H:i:s')
        && $mudancasTransferencia['prazo_solucao'] === $inicioTransferencia->modify('+180 minutes')->format('Y-m-d H:i:s'), 'Controller de transferência aplica prazos da área de destino.');
} finally {
    @unlink($tmp);
    $propPdo->setValue(null, $pdoAnterior); $propSettings->setValue(null, $settingsAnteriores);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, $usuarioAnterior);
    (new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, null);
    $_GET = $getAnterior; $_POST = $postAnterior; $_FILES = $filesAnteriores; $_SERVER = $servidorAnterior;
}

// SLA por área: prazos e regime herdam separadamente, inclusive com dados vindos do PDO.
$cacheSla = new ReflectionProperty(\App\Services\Settings::class, 'cache');
$cacheAnterior = $cacheSla->getValue();
try {
    $cacheSla->setValue(null, ['horario_comercial_ativo' => '1']);
    $area = ['sla_resposta_min' => '30', 'sla_solucao_min' => '120', 'usar_horario_comercial' => null];
    expect(Chamados::slaEfetivo([], $area) === ['resposta_min' => 30, 'solucao_min' => 120, 'comercial' => true, 'origem' => 'area'], 'Categoria vazia herda prazos da área e regime global.');
    $proprio = ['sla_resposta_min' => '15', 'sla_solucao_min' => '60', 'usar_horario_comercial' => '0'];
    expect(Chamados::slaEfetivo($proprio, $area) === ['resposta_min' => 15, 'solucao_min' => 60, 'comercial' => false, 'origem' => 'categoria'], 'Categoria prevalece sobre todos os padrões da área; zero mantém 24x7.');
    $misto = Chamados::slaEfetivo(['sla_resposta_min' => 15], $area);
    expect($misto['resposta_min'] === 15 && $misto['solucao_min'] === 120 && $misto['origem'] === 'categoria', 'Herança independente quando só um prazo é próprio.');
    expect(Chamados::slaEfetivo([], []) === ['resposta_min' => null, 'solucao_min' => null, 'comercial' => true, 'origem' => 'nenhum'], 'Sem prazos em nenhum nível fica sem SLA.');
    expect(!Chamados::slaEfetivo([], array_replace($area, ['usar_horario_comercial' => 0]))['comercial'], 'Área pode substituir calendário global por 24x7.');
    $cacheSla->setValue(null, ['horario_comercial_ativo' => '0']);
    expect(!Chamados::slaEfetivo([], [])['comercial'], 'Sem regime próprio segue padrão global desligado.');
    expect(Chamados::slaEfetivo([], ['usar_horario_comercial' => 1])['comercial'], 'Área com horário comercial explícito prevalece sobre padrão global.');
    expect(!Chamados::slaEfetivo(['usar_horario_comercial' => 0], ['usar_horario_comercial' => 1])['comercial'], 'Regime explícito da categoria prevalece sobre o da área.');

    $destino = ['id' => 'categoria-destino', 'area_id' => 'area-destino'];
    $quando = new DateTimeImmutable('2026-10-30 16:30:00');
    $areaDestino = array_replace($area, ['usar_horario_comercial' => 1]);
    $transferido = Chamados::transferir(chamadoFixture(), $destino, $quando, $areaDestino);
    expect($transferido['prazo_resposta'] === '2026-10-30 17:00:00' && $transferido['prazo_solucao'] === '2026-11-02 08:30:00', 'Transferência usa SLA e expediente da área de destino e pula fim de semana.');
    expect(json_decode($transferido['calendario_sla'], true)['inicio'] === '07:00', 'Calendário herdado é copiado para o chamado.');
    $semSla = Chamados::transferir(chamadoFixture(), $destino, $quando, []);
    expect($semSla['prazo_resposta'] === null && $semSla['prazo_solucao'] === null, 'Transferência para destino sem SLA remove prazos ainda não atendidos.');
    $respondido = array_replace(chamadoFixture(), ['respondido_em' => '2026-10-28 08:30:00']);
    $preservado = Chamados::transferir($respondido, $destino, $quando, $areaDestino);
    expect($preservado['prazo_resposta'] === $respondido['prazo_resposta'] && $preservado['prazo_solucao'] === '2026-11-02 08:30:00', 'Herança na transferência preserva primeira resposta atendida.');
    expect(Chamados::textoSla($destino, $areaDestino) === 'SLA em horário comercial, descontando dias sem expediente e feriados.', 'Texto de abertura comunica regime herdado.');

    $config = \App\Controllers\ServicosConfigController::class;
    expect($config::dadosSla([]) === ['sla_resposta_min' => null, 'sla_solucao_min' => null, 'usar_horario_comercial' => null], 'Formulário vazio persiste NULL e permite herança.');
    expect($config::dadosSla(['sla_resposta_atalho' => '15', 'sla_solucao_min' => '17', 'sla_solucao_atalho' => '30', 'usar_horario_comercial' => '0']) === ['sla_resposta_min' => 15, 'sla_solucao_min' => 17, 'usar_horario_comercial' => 0], 'Atalho e duração personalizada coexistem com precedência do valor manual.');
    foreach (['0', '-1', '1.5', '1e2', 'abc', '100801', ['15']] as $invalido) {
        rejeita(fn () => $config::dadosSla(['sla_resposta_min' => $invalido]), 'Entrada inválida não é truncada nem silenciada.');
    }
    rejeita(fn () => $config::dadosSla(['usar_horario_comercial' => '2']), 'Regime inválido recusado.');
    rejeita(fn () => $config::dadosSla(['sla_resposta_min' => '60', 'sla_solucao_min' => '15']), 'Solução menor que resposta recusada.');
    rejeita(fn () => $config::validarSlaEfetivo(['sla_solucao_min' => 15], $area), 'Solução própria não pode ser menor que resposta herdada.');
    rejeita(fn () => $config::validarSlaEfetivo(['sla_resposta_min' => 180], $area), 'Resposta própria não pode superar solução herdada.');
    $config::validarSlaEfetivo(['sla_resposta_min' => 120], $area);
    expect($config::dadosSla(['sla_resposta_min' => '1', 'sla_solucao_min' => '100800'])['sla_solucao_min'] === 100800, 'Limites de duração aceitos.');

    $fixture = chamadoViewFixture('abrir');
    $fixture['sla'] = Chamados::slaEfetivo($destino, $areaDestino);
    $fixture['textoSla'] = Chamados::textoSla($destino, $areaDestino);
    $html = View::partial('chamados/abrir', $fixture);
    expect(str_contains($html, '<strong>30 min</strong>') && str_contains($html, '<strong>2 h</strong>') && str_contains($html, 'SLA em horário comercial'), 'Abertura mostra prazos e regime efetivos da área.');
    $fixture = servicosConfigViewFixture();
    $html = View::partial('servicos-config/index', $fixture);
    expect(str_contains($html, 'SLA padrão de resposta') && str_contains($html, 'SLA padrão de solução'), 'Cadastro e edição da área oferecem seus padrões de SLA.');
    expect(str_contains($html, 'Herdado da área: 30 min') && str_contains($html, 'Herdado da área: 2 h') && str_contains($html, 'SLA da área'), 'Categoria mostra duração herdada e origem na lista.');
    expect(!preg_match('/<(script|style)\b|\s(?:style|onchange|onclick)=/i', $html), 'Controles de herança preservam a CSP.');
    $fixture['editarCategoria'] = $fixture['categorias'][0]['id'];
    $html = View::partial('servicos-config/index', $fixture);
    expect(substr_count($html, '<select name="usar_horario_comercial">') === 4, 'Área e categoria oferecem três regimes no cadastro e na edição.');
    expect(str_contains($html, '<option value="" selected>Herdar da área</option>'), 'Edição de categoria preserva NULL como herança explícita.');
} finally {
    $cacheSla->setValue(null, $cacheAnterior);
}

echo "Chamados: {$checks} verificações sem banco OK.\n";

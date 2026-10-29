<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\{PerfilController, UsuariosController};
use App\Core\{App, Auth, Database, Http, Session, View};
use App\Services\{LdapUsuarios, Settings};

/** Persistência simulada: somente os dados fictícios deste teste. */
final class PerfisPdo extends PDO
{
    public array $consultas = [];
    private bool $transacao = false;
    public function __construct(public Closure $responder) {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new PerfisStmt($this, $query); }
    public function beginTransaction(): bool { $this->transacao = true; return true; }
    public function inTransaction(): bool { return $this->transacao; }
    public function commit(): bool { $this->transacao = false; return true; }
    public function rollBack(): bool { $this->transacao = false; return true; }
}
final class PerfisStmt extends PDOStatement
{
    private array $rows = [];
    public function __construct(private PerfisPdo $db, private string $sql) {}
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

function verificar(bool $ok, string $mensagem): void
{
    if (!$ok) { throw new RuntimeException($mensagem); }
}

function ator(array $perfis, string $padrao = 'colaborador', array $extra = []): void
{
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, true);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, [
        'id' => '11111111-1111-4111-8111-111111111111', 'nome' => 'Pessoa de teste',
        'email' => 'teste@example.test', 'papel' => $padrao, 'perfis' => $perfis,
        'auth_origem' => 'local', 'ativo' => 1, 'must_change_password' => 0,
        'mfa_ativo' => 1, 'seguranca_patrimonial' => 1, ...$extra,
    ]);
    (new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, false);
    (new ReflectionProperty(Auth::class, 'areasGeridas'))->setValue(null, null);
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
Session::start();
$db = new PerfisPdo(static function (string $sql, array $params): array {
    if (str_starts_with($sql, 'SELECT area_id')) { return [['area_id' => 'area-a']]; }
    if (str_starts_with($sql, 'INSERT INTO `audit_log`')) { return []; }
    if (str_starts_with($sql, 'DELETE FROM usuario_perfis') || str_starts_with($sql, 'INSERT INTO `usuario_perfis`')) { return []; }
    throw new RuntimeException('Consulta inesperada: ' . $sql);
});
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $db);
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null, ['mfa_obrigatorio_admin' => '1']);

// Prévia isolada em loopback: php -S 127.0.0.1:8251 -t public tests/perfis.php.
if (PHP_SAPI === 'cli-server') {
    if (str_starts_with(Http::path(), '/assets/')) { return false; }
    Http::securityHeaders();
    $perfis = isset($_GET['unico']) ? ['colaborador'] : ['admin', 'porteiro', 'colaborador', 'gestor_area'];
    ator($perfis);
    if (Http::path() === '/conta/perfil' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        (new PerfilController())->trocar();
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); return; }
    $dados = ['titulo' => 'Perfis de acesso', 'flash' => Session::pullFlash(), 'old' => []];
    if (isset($_GET['form'])) {
        View::render('usuarios/form', [...$dados, 'u' => null, 'ldap' => false, 'areas' => [], 'pessoas' => []]);
    } else {
        echo View::partial('layout/app', [...$dados, 'content' => '<section class="cartao">Prévia com dados fictícios.</section>']);
    }
    return;
}

// Guardas que encerram a resposta são exercitadas em subprocessos.
if (($argv[1] ?? '') === '--guarda') {
    ator(['admin', 'colaborador'], 'colaborador', ['mfa_ativo' => ($argv[2] ?? '') === 'desativar' ? 1 : 0]);
    Session::set('perfil_ativo', 'colaborador');
    $_SERVER['REQUEST_URI'] = ($argv[2] ?? '') === 'desativar' ? '/conta/mfa/desativar' : '/chamados/meus';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    register_shutdown_function(static function (): void { echo '\nSTATUS=' . http_response_code(); });
    Auth::requireLogin();
    throw new RuntimeException('A guarda MFA deveria bloquear.');
}
if (($argv[1] ?? '') === '--csrf') {
    ator(['admin', 'colaborador']);
    $_POST = ['perfil' => 'admin'];
    register_shutdown_function(static function (): void { echo '\nSTATUS=' . http_response_code(); });
    (new PerfilController())->trocar();
    throw new RuntimeException('CSRF deveria bloquear.');
}
if (($argv[1] ?? '') === '--formulario') {
    ator(['admin'], 'admin');
    $_POST = ['perfis' => match ($argv[2] ?? '') {
        'vazio' => [], 'desconhecido' => ['root'], 'aninhado' => [['admin']], default => ['porteiro'],
    }];
    register_shutdown_function(static function (): void {
        echo isset($_SESSION['_flash'][0]) && $_SESSION['_flash'][0]['tipo'] === 'erro' ? 'FORMULARIO_RECUSADO' : 'SEM_ERRO';
    });
    (new ReflectionMethod(UsuariosController::class, 'perfisSelecionados'))->invoke(new UsuariosController(), '/usuarios/novo', 'admin');
    throw new RuntimeException('O cadastro deveria recusar perfis inválidos.');
}

$avisos = [];
set_error_handler(static function (int $nivel, string $texto) use (&$avisos): bool {
    $avisos[] = $texto;
    throw new RuntimeException($texto);
});
ator(['admin', 'colaborador', 'porteiro', 'gestor_area']);
Session::set('perfil_ativo', 'colaborador');
$sessao = session_id();
verificar(Auth::trocarPerfil('porteiro'), 'Troca para perfil concedido.');
verificar(session_id() !== $sessao, 'Troca regenera a sessão.');
verificar(Auth::is('porteiro') && Auth::can('portaria.operar') && !Auth::can('usuarios.gerir'), 'Porteiro não herda permissões do Admin concedido.');
verificar(Auth::user()['papel'] === 'porteiro' && Auth::user()['papel_padrao'] === 'colaborador', 'Controladores recebem o perfil ativo e o padrão separado.');
verificar(Auth::mfaObrigatorio(), 'MFA obrigatório mesmo com Admin inativo e padrão Solicitante.');
$auditorias = count($db->consultas);
$sessao = session_id();
verificar(!Auth::trocarPerfil('ADMIN') && !Auth::trocarPerfil('') && !Auth::trocarPerfil('root'), 'Perfis desconhecidos recusados.');
verificar(in_array('ssma', Auth::perfis(), true) && !in_array('ssma', Auth::perfisConcedidos(), true), 'Admin/TI pode trabalhar em qualquer perfil (como o Super-Admin do GLPI), sem que ele vire concessão.');
verificar(Auth::papel() === 'porteiro' && session_id() === $sessao && count($db->consultas) === $auditorias, 'Tentativa inválida não muda sessão nem gera auditoria de sucesso.');
verificar(Auth::trocarPerfil('porteiro') && session_id() === $sessao, 'Reenvio do perfil atual é idempotente.');
[$sqlAuditoria, $argsAuditoria] = $db->consultas[0];
verificar(str_contains($sqlAuditoria, 'audit_log') && in_array('TROCA_PERFIL', $argsAuditoria, true), 'Troca produz auditoria persistente.');
verificar(in_array('{"anterior":"colaborador","perfil":"porteiro"}', $argsAuditoria, true), 'Auditoria identifica os dois contextos.');

Auth::trocarPerfil('gestor_area');
verificar(Auth::areasGeridas() === ['area-a'] && Auth::gereArea('area-a') && !Auth::gereArea('area-b'), 'Gestor limitado às áreas onde tem papel gestor.');
$consultaAreas = current(array_filter($db->consultas, static fn ($q) => str_starts_with($q[0], 'SELECT area_id')));
verificar(str_contains($consultaAreas[0], "papel = 'gestor'") && $consultaAreas[1] === [Auth::id()], 'Escopo exige papel gestor e identidade autenticada.');
verificar(Auth::can('chamados.configurar_area') && Auth::can('chamados.atender') && !Auth::can('chamados.configurar') && !Auth::can('usuarios.gerir'), 'Gestor recebe somente configuração por área.');
verificar(!Auth::isSegurancaPatrimonial(), 'Gestor não herda autorização patrimonial residual.');
verificar(PerfilController::destino('/usuarios') === '/chamados/fila', 'Troca sai de página administrativa sem permissão.');
verificar(PerfilController::destino('/servicos/config?area_id=area-b') === '/servicos/config', 'Troca remove filtro de área alheia.');

Auth::trocarPerfil('admin');
verificar(Auth::areasGeridas() === null && Auth::gereArea('area-b'), 'Admin possui todas as áreas.');
foreach (array_keys((new ReflectionClass(Auth::class))->getConstant('PERMISSOES')) as $permissao) {
    verificar(Auth::can($permissao), 'Admin possui ' . $permissao);
}
verificar(PerfilController::destino('/usuarios?ativo=1') === '/usuarios?ativo=1', 'Mantém a página e os filtros quando autorizado.');

Auth::trocarPerfil('colaborador');
verificar(Auth::areasGeridas() === [] && !Auth::gereArea('area-a'), 'Solicitante não gere áreas.');
(new ReflectionProperty(Auth::class, 'membroArea'))->setValue(null, true);
foreach (array_keys((new ReflectionClass(Auth::class))->getConstant('PERMISSOES')) as $permissao) {
    verificar(Auth::can($permissao) === ($permissao === 'chamados.abrir'), 'Solicitante somente serviços: ' . $permissao);
}
foreach (['https://example.test', '//example.test', '/\\example.test', '/%2f%2fexample.test', "/usuarios\r\nLocation: x", '/usuarios/../configuracoes', '/desconhecida'] as $voltar) {
    verificar(PerfilController::destino($voltar) === '/chamados/meus', 'Retorno inseguro/desconhecido recusado.');
}
verificar(PerfilController::destino('/chamados/meus?status=novo') === '/chamados/meus?status=novo', 'Solicitante permanece em seus chamados.');

// Menu real, sem união de permissões entre os perfis concedidos.
$_SERVER['REQUEST_URI'] = '/chamados/meus';
$_SERVER['QUERY_STRING'] = '';
$html = View::partial('layout/app', ['titulo' => 'Teste', 'content' => '', 'flash' => []]);
$nav = explode('</nav>', explode('<nav>', $html)[1])[0];
verificar(str_contains($nav, '/chamados/abrir') && str_contains($nav, '/chamados/meus'), 'Menu Solicitante mostra serviços.');
foreach (['/usuarios', '/painel', '/portaria', '/chamados/fila', '/servicos/config', '/tutoriais'] as $rota) {
    verificar(!str_contains($nav, 'href="' . $rota . '"'), 'Menu Solicitante oculta ' . $rota);
}
verificar(str_contains($html, 'action="/conta/perfil"') && str_contains($html, 'aria-current="true"') && str_contains($html, 'name="_csrf"'), 'Seletor marca perfil ativo e envia POST com CSRF.');
$seletor = substr($html, (int) strrpos(substr($html, 0, (int) strpos($html, 'action="/conta/perfil"')), '<details'));
$seletor = explode('</details>', $seletor)[0];
verificar(!preg_match('/<(?:script|style)\b|\s(?:style|on\w+)\s*=/i', $seletor), 'Sem script/style inline no seletor.');
ator(['colaborador']);
$html = View::partial('layout/app', ['titulo' => 'Teste', 'content' => '', 'flash' => []]);
verificar(!str_contains($html, 'action="/conta/perfil"'), 'Seletor oculto para perfil único.');
foreach (['admin' => ['/usuarios', '/servicos/config', '/portaria'], 'porteiro' => ['/portaria'], 'gestor_area' => ['/chamados/fila', '/servicos/config']] as $perfil => $links) {
    ator(['admin', 'porteiro', 'colaborador', 'gestor_area']);
    Session::set('perfil_ativo', $perfil);
    $htmlMenu = View::partial('layout/app', ['titulo' => 'Teste', 'content' => '', 'flash' => []]);
    $navPerfil = explode('</nav>', explode('<nav>', $htmlMenu)[1])[0];
    foreach ($links as $link) { verificar(str_contains($navPerfil, 'href="' . $link . '"'), 'Menu ' . $perfil . ' mostra ' . $link); }
    if ($perfil !== 'admin') { verificar(!str_contains($navPerfil, 'href="/usuarios"'), 'Menu de perfil não administrativo oculta usuários.'); }
}

// Nova requisição: a sessão forjada/revogada não é fonte de concessões.
$concedidos = ['porteiro'];
$usuario = ['id' => 'usr-teste', 'nome' => 'Teste', 'papel' => 'porteiro', 'ativo' => 1];
$dbLeitura = new PerfisPdo(static function ($sql, $params) use (&$concedidos, $usuario): array {
    if (str_starts_with($sql, 'SELECT * FROM usuarios')) { return [$usuario]; }
    if (str_starts_with($sql, 'SELECT perfil')) { return array_map(static fn ($p) => ['perfil' => $p], $concedidos); }
    throw new RuntimeException('Consulta de carregamento inesperada: ' . $sql);
});
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $dbLeitura);
Session::set('uid', 'usr-teste');
Session::set('perfil_ativo', 'admin');
(new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, false);
verificar(Auth::user()['papel'] === 'porteiro' && Auth::perfis() === ['porteiro'], 'Perfil forjado da sessão é descartado.');
$concedidos = ['colaborador'];
(new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, false);
verificar(Auth::papel() === 'colaborador' && !Auth::can('portaria.operar'), 'Revogação entra em vigor na próxima requisição mesmo com padrão antigo.');
$concedidos = [];
(new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, false);
verificar(Auth::papel() === null && !Auth::can('chamados.abrir'), 'Conta sem concessões falha fechada.');
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $db);

// Cadastro exige que o padrão pertença à seleção; a gravação substitui concessões.
ator(['admin'], 'admin');
$controller = new UsuariosController();
$_POST = ['perfis' => ['admin', 'colaborador', 'admin']];
$metodo = new ReflectionMethod($controller, 'perfisSelecionados');
verificar($metodo->invoke($controller, '/usuarios/novo', 'colaborador') === ['admin', 'colaborador'], 'Cadastro aceita seleção múltipla sem duplicatas.');
(new ReflectionMethod($controller, 'aplicarPerfis'))->invoke($controller, Auth::id(), ['admin', 'colaborador']);
$gravaPerfis = array_values(array_filter($db->consultas, static fn ($q) => str_contains($q[0], 'usuario_perfis')));
verificar(count($gravaPerfis) === 3 && str_starts_with($gravaPerfis[0][0], 'DELETE') && $gravaPerfis[2][1][1] === 'colaborador', 'Persistência remove perfis revogados e grava toda seleção.');

// Mapeamento AD acumula grupos, preservando a precedência histórica do padrão.
$cfg = new ReflectionProperty(App::class, 'config');
$cfg->setValue(null, array_replace_recursive($cfg->getValue(), ['ldap' => [
    'group_admin' => 'CN=Admin,DC=teste', 'group_supervisor' => '', 'group_ssma' => '', 'group_porteiro' => 'CN=Portaria,DC=teste',
]]));
putenv('LDAP_GROUP_COLABORADOR=CN=Servicos,DC=teste');
putenv('LDAP_GROUP_GESTOR_AREA=CN=Gestores,DC=teste');
verificar(LdapUsuarios::perfisPorGrupos(['cn=servicos,dc=teste', 'CN=ADMIN,DC=TESTE', 'CN=Portaria,DC=teste', 'CN=Gestores,DC=teste']) === ['admin', 'porteiro', 'gestor_area', 'colaborador'], 'Todos os grupos correspondentes concedem perfis.');
verificar(LdapUsuarios::papelPorGrupos(['cn=servicos,dc=teste', 'cn=admin,dc=teste']) === 'admin', 'Prioridade histórica de Admin preservada.');
verificar(LdapUsuarios::perfisPorGrupos([]) === [] && LdapUsuarios::perfisPorGrupos(['CN=Outro,DC=teste']) === [], 'AD não concede perfil sem grupo correspondente.');

// Sincronização real com PDO simulado: remoção de grupos revoga perfis e troca o padrão.
$cfg->setValue(null, array_replace_recursive($cfg->getValue(), ['ldap' => ['sync_roles' => true, 'group_seg_patrimonial' => '']]));
$uAd = ['id' => 'usr-ad', 'papel' => 'porteiro', 'pessoa_id' => 'pessoa-vinculada', 'ativo' => 1, 'seguranca_patrimonial' => 0];
$entradaAd = ['login' => 'teste.ad', 'dn' => 'CN=Teste,DC=teste', 'nome' => 'Teste AD', 'email' => '', 'matricula' => null, 'grupos' => ['cn=servicos,dc=teste']];
$dbAd = new PerfisPdo(static function ($sql, $params) use ($uAd): array {
    if (str_starts_with($sql, 'SELECT perfil')) { return [['perfil' => 'admin'], ['perfil' => 'porteiro']]; }
    if (str_starts_with($sql, 'SELECT 1 FROM usuarios u')) { return [[1]]; }
    if (str_starts_with($sql, 'SELECT * FROM usuarios')) { return [$uAd]; }
    if (str_starts_with($sql, 'UPDATE ') || str_starts_with($sql, 'INSERT ') || str_starts_with($sql, 'DELETE ')) { return []; }
    throw new RuntimeException('Consulta AD inesperada: ' . $sql);
});
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $dbAd);
LdapUsuarios::sincronizar($uAd, $entradaAd, 'TESTE');
$insercoes = array_values(array_filter($dbAd->consultas, static fn ($q) => str_starts_with($q[0], 'INSERT INTO `usuario_perfis`')));
verificar(count($insercoes) === 1 && $insercoes[0][1] === ['usr-ad', 'colaborador'], 'AD revoga perfis de grupos removidos e mantém somente os atuais.');
$atualizacao = current(array_filter($dbAd->consultas, static fn ($q) => str_starts_with($q[0], 'UPDATE `usuarios`')));
verificar(in_array('colaborador', $atualizacao[1], true) && !$dbAd->inTransaction(), 'AD ajusta o padrão removido na mesma transação das concessões.');

// Último Admin com padrão não administrativo não pode perder a concessão automaticamente.
$dbAd->responder = static function ($sql, $params) use ($uAd): array {
    if (str_starts_with($sql, 'SELECT perfil')) { return [['perfil' => 'admin'], ['perfil' => 'porteiro']]; }
    if (str_starts_with($sql, 'SELECT 1 FROM usuarios u')) { return []; }
    if (str_starts_with($sql, 'SELECT * FROM usuarios')) { return [$uAd]; }
    if (str_starts_with($sql, 'UPDATE ') || str_starts_with($sql, 'INSERT ') || str_starts_with($sql, 'DELETE ')) { return []; }
    throw new RuntimeException('Consulta AD inesperada: ' . $sql);
};
$dbAd->consultas = [];
LdapUsuarios::sincronizar($uAd, $entradaAd, 'TESTE');
$insercoes = array_values(array_filter($dbAd->consultas, static fn ($q) => str_starts_with($q[0], 'INSERT INTO `usuario_perfis`')));
verificar(in_array(['usr-ad', 'admin'], array_column($insercoes, 1), true), 'AD preserva último Admin mesmo com padrão Porteiro.');
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $db);
putenv('LDAP_GROUP_COLABORADOR'); putenv('LDAP_GROUP_GESTOR_AREA');

// Migração simulada: conserva o padrão, concede gestor e deduplica ao repetir.
$sql = file_get_contents(App::root('database/migrations/013_perfis_multiplos.sql'));
verificar(str_contains($sql, 'PRIMARY KEY (usuario_id, perfil)') && str_contains($sql, 'ON DELETE CASCADE'), 'Migração evita concessões duplicadas e órfãs.');
verificar(preg_match('/INSERT IGNORE INTO usuario_perfis.*?SELECT id, papel FROM usuarios/s', $sql) === 1, 'Migração copia o perfil existente.');
verificar(preg_match("/SELECT usuario_id, 'gestor_area' FROM servico_area_membros WHERE papel = 'gestor'/", $sql) === 1, 'Migração concede gestor somente a gestores de área.');
$legados = ['u1' => 'admin', 'u2' => 'colaborador', 'u3' => 'porteiro'];
$membros = [['u2', 'gestor'], ['u3', 'atendente']]; $resultado = [];
for ($i = 0; $i < 2; $i++) {
    foreach ($legados as $uid => $perfil) { $resultado[$uid][$perfil] = true; }
    foreach ($membros as [$uid, $papel]) { if ($papel === 'gestor') { $resultado[$uid]['gestor_area'] = true; } }
}
verificar(array_keys($resultado['u2']) === ['colaborador', 'gestor_area'] && array_keys($resultado['u3']) === ['porteiro'] && count($resultado['u1']) === 1, 'Cópia simulada preserva históricos e é idempotente.');

foreach ([['--guarda', 'cadastro'], ['--guarda', 'desativar'], ['--csrf']] as $args) {
    $processo = proc_open([PHP_BINARY, __FILE__, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $saida = stream_get_contents($pipes[1]); $erro = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $codigo = proc_close($processo);
    verificar($codigo === 0 && str_contains($saida, 'STATUS=403'), 'Guarda real bloqueia ' . implode('/', $args) . ': ' . $saida . $erro);
}
foreach (['vazio', 'desconhecido', 'aninhado', 'padrao_ausente'] as $caso) {
    $processo = proc_open([PHP_BINARY, __FILE__, '--formulario', $caso], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $saida = stream_get_contents($pipes[1]); $erro = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $codigo = proc_close($processo);
    verificar($codigo === 0 && $saida === 'FORMULARIO_RECUSADO', 'Cadastro recusa ' . $caso . ': ' . $saida . $erro);
}
verificar($avisos === [], 'Nenhum aviso PHP.');
echo "Perfis: sessão, revogação, RBAC, menu, áreas, MFA, CSRF, cadastro, AD e migração simulada OK.\n";

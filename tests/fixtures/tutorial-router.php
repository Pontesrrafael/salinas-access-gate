<?php
// Somente para testes locais: php -S 127.0.0.1:8238 -t public tests/fixtures/tutorial-router.php
declare(strict_types=1);
if (str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/assets/')) { return false; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';
App\Core\Http::securityHeaders();
$role = in_array($_GET['role'] ?? '', ['admin', 'porteiro', 'ssma', 'supervisor'], true) ? $_GET['role'] : 'admin';
(new ReflectionProperty(App\Core\Auth::class, 'loaded'))->setValue(null, true);
(new ReflectionProperty(App\Core\Auth::class, 'user'))->setValue(null, ['id' => 'fixture-' . $role, 'nome' => 'Usuário de teste', 'papel' => $role]);
// Views reais com dados fictícios para conferir traduções sem banco/credenciais.
$fixturePath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($fixturePath === '/login') {
    App\Core\View::render('auth/login', ['titulo' => 'Entrar', 'old' => [], 'ldap' => !empty($_GET['ldap'])], 'layout/simples');
    return;
}
if ($fixturePath === '/pessoas') {
    $filtro = ['q' => $_GET['q'] ?? '', 'categoria' => '', 'situacao' => 'ativos'];
    $rows = [['id' => 'pessoa-original', 'nome' => 'Pessoas', 'empresa' => 'Entrada', 'categoria' => 'visitante', 'usuario_id' => null, 'ativo' => 1, 'cpf_exibicao' => '000.***.***-00', 'matricula' => '', 'telefone_principal' => '', 'email_principal' => '', 'documentos' => []]];
    App\Core\View::render('pessoas/index', ['titulo' => 'Pessoas', 'filtro' => $filtro, 'rows' => $rows, 'total' => 1, 'pagina' => 1, 'paginas' => 1]);
    return;
}
if (in_array($fixturePath, ['/pessoas/nova', '/pessoas/pessoa-ficticia'], true)) {
    $p = $fixturePath === '/pessoas/nova' ? null : ['id' => 'pessoa-ficticia', 'nome' => 'Pessoa de teste', 'usuario' => null, 'ativo' => 1, 'categoria' => 'visitante'];
    App\Core\View::render('pessoas/form', ['titulo' => 'Pessoa de teste', 'p' => $p, 'old' => [], 'docs' => [], 'usuariosLivres' => [], 'acessos' => []]);
    return;
}
if ($fixturePath === '/veiculos/novo') {
    App\Core\View::render('veiculos/form', ['titulo' => 'Novo veículo', 'v' => null, 'old' => []]);
    return;
}
if ($fixturePath === '/usuarios/novo') {
    App\Core\View::render('usuarios/form', ['titulo' => 'Novo usuário', 'u' => null, 'old' => [], 'areas' => [], 'pessoas' => [], 'ldap' => false]);
    return;
}
if ($fixturePath === '/ssma/solicitacao-ficticia') {
    $s = ['id' => 'solicitacao-ficticia', 'visitante_nome' => 'Pessoa de teste', 'data_prevista' => '2026-10-27', 'motivo' => 'Atividade de teste'];
    App\Core\View::render('ssma/form', ['titulo' => 'Verificação SSMA', 's' => $s, 'pessoa' => null, 'docs' => [], 'checklist' => ['aso' => 'ASO'], 'old' => []]);
    return;
}
$modulos = App\Services\Tutoriais::permitidos();
$titulo = 'Ambiente de verificação';
$content = str_starts_with($_SERVER['REQUEST_URI'], '/tutoriais')
    ? App\Core\View::partial('tutoriais/index', compact('modulos'))
    : '<section class="cartao"><form class="filtros" data-tour="filtros"><label>Busca <input name="busca"></label><button type="submit">Consultar</button></form><p>Nenhum registro encontrado.</p></section>';
echo App\Core\View::partial('layout/app', compact('content', 'titulo'));

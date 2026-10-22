<?php
// Somente para testes locais: php -S 127.0.0.1:8238 -t public tests/fixtures/tutorial-router.php
declare(strict_types=1);
if (str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/assets/')) { return false; }
require dirname(__DIR__, 2) . '/app/bootstrap.php';
App\Core\Http::securityHeaders();
$role = in_array($_GET['role'] ?? '', ['admin', 'porteiro', 'ssma', 'supervisor'], true) ? $_GET['role'] : 'admin';
(new ReflectionProperty(App\Core\Auth::class, 'loaded'))->setValue(null, true);
(new ReflectionProperty(App\Core\Auth::class, 'user'))->setValue(null, ['id' => 'fixture-' . $role, 'nome' => 'Usuário de teste', 'papel' => $role]);
$modulos = App\Services\Tutoriais::permitidos();
$titulo = 'Ambiente de verificação';
$content = str_starts_with($_SERVER['REQUEST_URI'], '/tutoriais')
    ? App\Core\View::partial('tutoriais/index', compact('modulos'))
    : '<section class="cartao"><form class="filtros" data-tour="filtros"><label>Busca <input name="busca"></label><button type="submit">Consultar</button></form><p>Nenhum registro encontrado.</p></section>';
echo App\Core\View::partial('layout/app', compact('content', 'titulo'));

<?php
declare(strict_types=1);

/**
 * Front controller — único ponto de entrada web.
 * DocumentRoot do Apache/Nginx deve apontar para esta pasta (public/).
 */

// Servidor embutido do PHP (php -S): entrega arquivos estáticos diretamente
if (PHP_SAPI === 'cli-server') {
    $arquivo = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($arquivo !== __DIR__ . '/' && is_file($arquivo)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Http;
use App\Core\Router;
use App\Core\Session;

Http::securityHeaders();

try {
    // POST maior que post_max_size chega vazio: avisa em vez de acusar falha de CSRF
    if (Http::method() === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        Session::start();
        Http::abort(400, 'O envio excedeu o tamanho máximo permitido pelo servidor (post_max_size). Reduza a quantidade ou o tamanho das fotos.');
    }
    if (Http::path() !== '/saude') {
        Session::start();
    }
    $router = new Router();
    require App::root('app/routes.php');
    $router->dispatch(Http::method(), Http::path());
} catch (Throwable $e) {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    App::log('ERRO ' . $ref . ': ' . $e->getMessage(), ['arquivo' => $e->getFile() . ':' . $e->getLine()]);
    error_log('[' . $ref . '] ' . $e);
    $msg = App::config('app')['debug']
        ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : 'Ocorreu um erro inesperado. Referência: ' . $ref;
    if (!headers_sent()) {
        Http::abort(500, $msg);
    }
    echo '<p>' . e($msg) . '</p>';
}

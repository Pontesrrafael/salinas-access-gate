<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Roteador simples: GET/POST com parâmetros {nome}. Todo POST passa por CSRF.
 */
final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable|array}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $allowed = [];
        foreach ($this->routes as [$m, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9\-_]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            if ($m !== $method) {
                $allowed[] = $m;
                continue;
            }
            if ($method === 'POST') {
                Csrf::verify();
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            if (is_array($handler)) {
                [$class, $action] = $handler;
                (new $class())->$action(...array_values($params));
            } else {
                $handler(...array_values($params));
            }
            return;
        }
        if ($allowed) {
            Http::abort(405);
        }
        Http::abort(404, 'O endereço solicitado não existe.');
    }
}

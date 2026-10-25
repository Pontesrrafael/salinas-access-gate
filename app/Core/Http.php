<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Utilidades HTTP: entrada, redirecionamento, JSON, erros e caminho base.
 */
final class Http
{
    private static ?string $nonce = null;

    public static function cspNonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(24));
    }
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Caminho base quando a aplicação roda em subpasta (ex.: /salinas-access-gate/public). */
    public static function basePath(): string
    {
        static $base = null;
        if ($base === null) {
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            // No Windows, dirname('/index.php') devolve '\' — normaliza as barras
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            $base = ($dir === '' || $dir === '.') ? '' : $dir;
        }
        return $base;
    }

    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = self::basePath();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        } elseif ($base !== '' && str_ends_with($base, '/public')) {
            // Acesso pela raiz do projeto (.htaccess da raiz reescreve para public/)
            $raiz = substr($base, 0, -strlen('/public'));
            if ($raiz === '' || str_starts_with($uri, $raiz . '/') || $uri === $raiz) {
                $uri = substr($uri, strlen($raiz));
            }
        }
        $uri = '/' . trim(rawurldecode($uri), '/');
        return $uri === '/index.php' ? '/' : $uri;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_string($v) ? trim($v) : $default;
    }

    /** Texto opcional: string vazia vira null. */
    public static function nstr(string $key, int $max = 1000): ?string
    {
        $v = self::str($key);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    public static function bool(string $key): bool
    {
        $v = self::input($key);
        return in_array($v, ['1', 'on', 'true', 'sim', 1, true], true);
    }

    public static function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return self::fromTrustedProxy() && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (self::fromTrustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $proxies = App::config('seguranca')['trusted_proxies'] ?? [];
            foreach (array_reverse(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])) as $part) {
                $ip = trim($part);
                if (in_array($ip, $proxies, true)) {
                    continue;
                }
                return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $remote;
            }
        }
        return $remote;
    }

    private static function fromTrustedProxy(): bool
    {
        $proxies = App::config('seguranca')['trusted_proxies'] ?? [];
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', $proxies, true);
    }

    public static function url(string $path = '/', array $query = []): string
    {
        $url = self::basePath() . '/' . ltrim($path, '/');
        if ($url === '') {
            $url = '/';
        }
        return $query ? $url . '?' . http_build_query($query) : $url;
    }

    /** URL absoluta (usada em links externos de solicitação). */
    public static function absoluteUrl(string $path): string
    {
        $app = App::config('app');
        $base = rtrim((string) ($app['url'] ?? ''), '/');
        if ($base === '') {
            if (($app['env'] ?? '') === 'production') {
                throw new RuntimeException('APP_URL deve estar configurado em produção para gerar links absolutos.');
            }
            $scheme = self::isHttps() ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = $scheme . '://' . $host . self::basePath();
        }
        return $base . '/' . ltrim($path, '/');
    }

    public static function redirect(string $path, array $query = []): never
    {
        header('Location: ' . self::url($path, $query), true, 303);
        exit;
    }

    public static function back(string $fallback = '/'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
            header('Location: ' . $ref, true, 303);
            exit;
        }
        self::redirect($fallback);
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function abort(int $status, string $mensagem = ''): never
    {
        http_response_code($status);
        if (self::isAjax()) {
            self::json(['erro' => $mensagem ?: 'Erro ' . $status], $status);
        }
        $titulos = [
            400 => 'Requisição inválida', 403 => 'Acesso negado', 404 => 'Página não encontrada',
            405 => 'Método não permitido', 409 => 'Já processado', 410 => 'Link expirado',
            419 => 'Sessão expirada', 422 => 'Dados inválidos', 429 => 'Muitas tentativas',
            500 => 'Erro interno',
        ];
        View::render('errors/erro', [
            'titulo'   => $titulos[$status] ?? 'Erro',
            'status'   => $status,
            'mensagem' => $mensagem,
        ], 'layout/simples');
        exit;
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        $mapaInfleet = self::path() === '/infleet/percursos';
        header('Referrer-Policy: ' . ($mapaInfleet ? 'strict-origin-when-cross-origin' : 'same-origin'));
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
        $tiles = $mapaInfleet ? ' https://tile.openstreetmap.org' : '';
        $csp = "default-src 'self'; img-src 'self' data: blob:" . $tiles . "; style-src 'self'; script-src 'self' https://challenges.cloudflare.com; "
             . "frame-src https://challenges.cloudflare.com; connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'";
        if ($mapaInfleet && (App::config('google_maps')['enabled'] ?? false) && trim((string) (App::config('google_maps')['browser_key'] ?? '')) !== '') {
            $nonce = self::cspNonce();
            // Nonce por resposta, conforme orientação Google; exceções limitadas à página de mapas.
            $csp = "default-src 'self'; script-src 'self' 'nonce-{$nonce}' 'strict-dynamic' https://maps.googleapis.com https://maps.gstatic.com 'unsafe-eval' blob:; "
                . "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com; "
                . "img-src 'self' data: blob: https://tile.openstreetmap.org https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com; "
                . "connect-src 'self' https://*.googleapis.com https://*.gstatic.com https://*.google.com data: blob:; "
                . "font-src 'self' https://fonts.gstatic.com; frame-src https://*.google.com; worker-src blob:; "
                . "form-action 'self'; base-uri 'self'; frame-ancestors 'none'";
        }
        header('Content-Security-Policy: ' . $csp);
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sessão PHP endurecida: cookie HttpOnly + SameSite=Lax (+ Secure em HTTPS),
 * modo estrito, expiração por inatividade e mensagens "flash".
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = App::config('session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        $savePath = App::root('storage/sessions');
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0770, true);
        }
        session_save_path($savePath);
        session_name($cfg['nome']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Http::basePath() === '' ? '/' : Http::basePath() . '/',
            'secure'   => $cfg['cookie_secure'] || Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $agora = time();
        $idle = $cfg['idle_minutos'] * 60;
        if (isset($_SESSION['_ultimo_acesso']) && ($agora - (int) $_SESSION['_ultimo_acesso']) > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
            self::flash('aviso', 'Sua sessão expirou por inatividade. Entre novamente.');
        }
        $_SESSION['_ultimo_acesso'] = $agora;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Tipos: sucesso | erro | aviso | info */
    public static function flash(string $tipo, string $mensagem): void
    {
        $_SESSION['_flash'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
    }

    /** @return array<int, array{tipo:string, mensagem:string}> */
    public static function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /** Guarda dados do formulário para repopular após erro de validação. */
    public static function flashOld(array $data): void
    {
        unset($data['_csrf']);
        foreach (array_keys($data) as $k) {
            if (preg_match('/senha|password|secret|token|pem/i', (string) $k)) {
                unset($data[$k]); // nunca guardar segredos na sessão
            }
        }
        $_SESSION['_old'] = $data;
    }

    public static function pullOld(): array
    {
        $o = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
        return $o;
    }
}

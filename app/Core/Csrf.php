<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Proteção CSRF (token sincronizado por sessão) para todo POST.
 * Aceita o token no campo _csrf ou no cabeçalho X-CSRF-Token (fetch/AJAX).
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $valid = is_string($sent) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $sent);
        if (!$valid) {
            Http::abort(403, 'O formulário expirou (proteção CSRF). Recarregue a página e tente novamente.');
        }
    }
}

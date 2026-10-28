<?php
declare(strict_types=1);

namespace App\Core;

/** Tradução explícita de textos da interface, sem alterar dados e identificadores. */
final class I18n
{
    public const LOCALES = ['pt-BR' => 'Português', 'en' => 'English', 'es' => 'Español'];
    private static ?string $locale = null;
    private static array $catalogs = [];

    public static function init(): void
    {
        if (self::$locale !== null) { return; }
        $cookie = $_COOKIE['sag_idioma'] ?? null;
        self::$locale = is_string($cookie) && isset(self::LOCALES[$cookie]) ? $cookie : 'pt-BR';
        // A preferência só é alterada por GET; nunca lê ou reenvia campos de negócio.
        $requested = $_GET['lang'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && is_string($requested) && isset(self::LOCALES[$requested])) {
            self::$locale = $requested;
            if (!headers_sent()) {
                setcookie('sag_idioma', $requested, [
                    'expires' => time() + 365 * 86400,
                    'path' => (Http::basePath() ?: '') . '/',
                    'secure' => Http::isHttps(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
        if (PHP_SAPI !== 'cli' && !headers_sent()) { header('Content-Language: ' . self::$locale); }
    }

    public static function current(): string
    {
        self::init();
        return self::$locale;
    }

    public static function messages(): array
    {
        $locale = self::current();
        if ($locale === 'pt-BR') { return []; }
        if (!isset(self::$catalogs[$locale])) {
            $file = App::root('app/Lang/' . $locale . '.json');
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$catalogs[$locale] = is_array($data)
                ? array_filter($data, static fn ($value, $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH)
                : [];
        }
        return self::$catalogs[$locale];
    }

    public static function t(string $source, array $params = []): string
    {
        $source = str_replace("\r\n", "\n", $source);
        $message = self::messages()[$source] ?? $source;
        $replace = [];
        foreach ($params as $key => $value) {
            if (!is_scalar($value) && $value !== null) { continue; }
            $key = trim((string) $key, '{}:');
            $replace['{' . $key . '}'] = (string) $value;
            $replace[':' . $key] = (string) $value;
        }
        return $replace ? strtr($message, $replace) : $message;
    }

    /** Traduz somente campos de UI gerados pelo sistema em respostas estruturadas. */
    public static function response(array $data, array $path = []): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['avisos', 'bloqueios'], true) && is_array($value)) {
                $data[$key] = array_map(static fn ($message) => is_string($message) ? self::t($message) : $message, $value);
            } elseif (is_string($value) && (in_array($key, ['erro', 'mensagem', 'categoria_label', 'status_label'], true)
                || ($key === 'rotulo' && in_array('documentos', $path, true)))) {
                $data[$key] = self::t($value);
            } elseif (is_array($value)) {
                $data[$key] = self::response($value, [...$path, $key]);
            }
        }
        return $data;
    }
}

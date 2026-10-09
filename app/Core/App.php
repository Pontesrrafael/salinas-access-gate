<?php
declare(strict_types=1);

namespace App\Core;

final class App
{
    private static array $config = [];
    private static string $root = '';

    public static function boot(string $root): void
    {
        self::$root = $root;
        Env::load($root . '/.env');
        self::$config = require $root . '/config/config.php';
        date_default_timezone_set(self::$config['app']['timezone']);
        mb_internal_encoding('UTF-8');

        $debug = self::$config['app']['debug'];
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', $root . '/storage/logs/php-erros.log');
    }

    public static function config(string $section): array
    {
        return self::$config[$section] ?? [];
    }

    public static function root(string $path = ''): string
    {
        return self::$root . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public static function isProduction(): bool
    {
        return self::$config['app']['env'] === 'production';
    }

    public static function log(string $msg, array $ctx = []): void
    {
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $msg, $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : '');
        @file_put_contents(self::root('storage/logs/app.log'), $line, FILE_APPEND | LOCK_EX);
    }
}

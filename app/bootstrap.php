<?php
declare(strict_types=1);

/**
 * Inicialização comum (web e CLI): autoload PSR-4 do namespace App\, .env, config e helpers.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

App\Core\App::boot(dirname(__DIR__));
App\Core\I18n::init();

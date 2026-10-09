<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Renderização de templates PHP com layout. Todo dado dinâmico deve sair por e().
 */
final class View
{
    /** Conteúdos definidos pela view para o layout (ex.: botões de ação no topo). */
    public static array $slots = [];

    public static function render(string $template, array $data = [], string $layout = 'layout/app'): void
    {
        self::$slots = [];
        $content = self::partial($template, $data);
        echo self::partial($layout, [...$data, ...self::$slots, 'content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = App::root('app/Views/' . $template . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException('View não encontrada: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}

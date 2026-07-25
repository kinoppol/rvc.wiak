<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $vars = []): string
    {
        $file = APP_ROOT . '/app/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function layout(string $view, array $vars = []): string
    {
        $content = self::render($view, $vars);
        return self::render('layout', $vars + ['content' => $content]);
    }
}

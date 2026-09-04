<?php
declare(strict_types=1);

namespace App\Support;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'admin'): string
    {
        $base = SERVMON_BASE_DIR . '/app/Views/';

        $load = static function (string $file, array $vars): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            require $file;
            return (string) ob_get_clean();
        };

        $content = $load($base . $template . '.php', $data);

        if ($layout === 'none') {
            return $content;
        }

        return $load($base . 'layouts/' . $layout . '.php', ['content' => $content] + $data);
    }
}

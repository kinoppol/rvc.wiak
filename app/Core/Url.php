<?php
declare(strict_types=1);

namespace App\Core;

final class Url
{
    public static function base(): string
    {
        return rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    }

    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }

    public static function asset(string $path): string
    {
        $rel = ltrim($path, '/');
        $url = self::base() . '/public/assets/' . $rel;

        // Cache-bust with the file's mtime so a Cloudflare/browser cache miss
        // happens automatically on deploy — CSS/JS have no build step here,
        // so without this a long edge cache TTL can serve a stale app.js for
        // hours/days after a push even though the origin file already changed.
        $file = APP_ROOT . '/public/assets/' . $rel;
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
        return $url;
    }
}

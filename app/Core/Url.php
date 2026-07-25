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
        return self::base() . '/public/assets/' . ltrim($path, '/');
    }
}

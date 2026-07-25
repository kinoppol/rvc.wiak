<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $st = Database::pdo()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public static function set(string $key, string $value): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $st->execute([$key, $value]);
    }
}

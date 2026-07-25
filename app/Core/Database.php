<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;
    private static array $cfg = [];

    public static function configure(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = self::$cfg;
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $c['host'] ?? '127.0.0.1',
                $c['port'] ?? '3306',
                $c['name'] ?? '',
                $c['charset'] ?? 'utf8mb4'
            );
            try {
                self::$pdo = new PDO($dsn, $c['user'] ?? 'root', $c['pass'] ?? '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new PDOException('ไม่สามารถเชื่อมต่อฐานข้อมูลได้: ' . $e->getMessage(), (int) $e->getCode());
            }
        }
        return self::$pdo;
    }
}

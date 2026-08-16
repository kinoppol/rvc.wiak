<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use RuntimeException;

/**
 * File-based schema migrations, run manually by an admin from /admin/migrations.
 * There is no staging environment and deploys are a plain `git reset --hard`
 * (see CLAUDE.md), so schema changes need a way to reach production without
 * SSH/phpMyAdmin access. Files under database/migrations/*.sql are numbered,
 * must be idempotent (CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS,
 * same convention as database/schema.sql), and are tracked in
 * `schema_migrations` once applied so they never re-run automatically.
 */
final class Migration
{
    private const DIR = __DIR__ . '/../../database/migrations';

    public static function files(): array
    {
        if (!is_dir(self::DIR)) {
            return [];
        }
        $files = glob(self::DIR . '/*.sql') ?: [];
        sort($files);
        return array_map('basename', $files);
    }

    public static function read(string $filename): string
    {
        $path = self::resolvePath($filename);
        return file_get_contents($path) ?: '';
    }

    private static function resolvePath(string $filename): string
    {
        // basename() strips any directory component so a crafted filename
        // like "../../config/config.php" can never escape database/migrations/.
        $safe = basename($filename);
        if (!in_array($safe, self::files(), true)) {
            throw new RuntimeException('ไม่พบไฟล์ migration นี้');
        }
        return self::DIR . '/' . $safe;
    }

    private static function ensureTable(): void
    {
        Database::pdo()->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                filename   VARCHAR(190) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                applied_by INT UNSIGNED NULL,
                PRIMARY KEY (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public static function appliedMap(): array
    {
        self::ensureTable();
        $rows = Database::pdo()->query('SELECT filename, applied_at, applied_by FROM schema_migrations')->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['filename']] = $r;
        }
        return $map;
    }

    public static function pending(): array
    {
        $applied = self::appliedMap();
        return array_values(array_filter(self::files(), fn ($f) => !isset($applied[$f])));
    }

    public static function history(): array
    {
        self::ensureTable();
        return Database::pdo()->query(
            'SELECT m.*, u.full_name AS applied_by_name
             FROM schema_migrations m
             LEFT JOIN users u ON u.id = m.applied_by
             ORDER BY m.applied_at DESC'
        )->fetchAll();
    }

    /**
     * Splits a .sql file into individual statements: strips whole-line `--`
     * comments (the only comment style used in this project's SQL files),
     * then splits on `;`. Good enough for the plain CREATE/ALTER/INSERT
     * statements these migration files are expected to contain.
     */
    public static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }
        $parts = explode(';', implode("\n", $clean));
        $parts = array_map('trim', $parts);
        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /** @return array{filename:string,statements:int} */
    public static function run(string $filename, int $userId): array
    {
        self::ensureTable();
        $path = self::resolvePath($filename);
        $safe = basename($path);

        if (isset(self::appliedMap()[$safe])) {
            throw new RuntimeException("migration '{$safe}' ถูกรันไปแล้ว");
        }

        $statements = self::splitStatements(file_get_contents($path) ?: '');
        if (!$statements) {
            throw new RuntimeException("ไฟล์ '{$safe}' ไม่มีคำสั่ง SQL");
        }

        $pdo = Database::pdo();
        $executed = 0;
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
            $executed++;
        }

        $st = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_by) VALUES (?, ?)');
        $st->execute([$safe, $userId]);

        return ['filename' => $safe, 'statements' => $executed];
    }

    /** @return array<int, array{filename:string,statements:int}> */
    public static function runAllPending(int $userId): array
    {
        $results = [];
        foreach (self::pending() as $f) {
            $results[] = self::run($f, $userId);
        }
        return $results;
    }
}

<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Upload;

/**
 * Installer files for the Windows notifier. Kept under the uploads directory
 * (git-ignored, so a deploy's `git reset --hard` leaves them alone, and denied
 * to direct requests); they are only served through the login-checked route.
 */
final class DesktopRelease
{
    public const MAX_BYTES = 500 * 1024 * 1024;
    public const VERSION_PATTERN = '/^\d{1,5}\.\d{1,5}\.\d{1,5}(-[0-9A-Za-z.]{1,16})?$/';

    /** Each allowed extension and the bytes a real file of that type starts with. */
    private const SIGNATURES = ['exe' => 'MZ', 'msi' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'nupkg' => "PK\x03\x04"];

    /** True once both desktop migrations have run. */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = (bool) Database::pdo()->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'desktop_releases' AND COLUMN_NAME = 'package_stored'"
            )->fetchColumn();
        }
        return $ready;
    }

    public static function packagePath(array $release): string
    {
        return self::dir() . '/' . basename((string) $release['package_stored']);
    }

    public static function attachPackage(int $id, array $p): void
    {
        Database::pdo()->prepare(
            'UPDATE desktop_releases SET package_stored = ?, package_name = ?, package_size = ?, package_sha1 = ?, package_sha256 = ? WHERE id = ?'
        )->execute([$p['stored'], $p['name'], $p['size'], $p['sha1'], $p['sha256'], $id]);
    }

    public static function dir(): string
    {
        return Upload::dir() . '/desktop-releases';
    }

    public static function path(array $release): string
    {
        return self::dir() . '/' . basename((string) $release['stored_name']);
    }

    public static function extensionOf(string $fileName): ?string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return isset(self::SIGNATURES[$ext]) ? $ext : null;
    }

    public static function hasValidSignature(string $path, string $ext): bool
    {
        $sig = self::SIGNATURES[$ext] ?? null;
        $fh = $sig !== null ? @fopen($path, 'rb') : false;
        if (!$fh) {
            return false;
        }
        $head = fread($fh, strlen($sig));
        fclose($fh);
        return $head === $sig;
    }

    /** @return array<int,array> newest first */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT r.*, u.full_name AS uploaded_by_name FROM desktop_releases r
             LEFT JOIN users u ON u.id = r.uploaded_by ORDER BY r.created_at DESC, r.id DESC'
        )->fetchAll();
    }

    public static function current(): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $row = Database::pdo()->query('SELECT * FROM desktop_releases WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM desktop_releases WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function versionExists(string $version): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM desktop_releases WHERE version = ?');
        $st->execute([$version]);
        return (bool) $st->fetchColumn();
    }

    public static function create(array $d): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO desktop_releases (version, stored_name, extension, size_bytes, sha256, notes, uploaded_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$d['version'], $d['stored_name'], $d['extension'], $d['size_bytes'], $d['sha256'], $d['notes'], $d['uploaded_by']]);
        return (int) $pdo->lastInsertId();
    }

    public static function setCurrent(int $id): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE desktop_releases SET is_current = 0 WHERE is_current = 1');
            $pdo->prepare('UPDATE desktop_releases SET is_current = 1 WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function delete(array $release): void
    {
        Database::pdo()->prepare('DELETE FROM desktop_releases WHERE id = ?')->execute([$release['id']]);
        $paths = [self::path($release)];
        if (!empty($release['package_stored'])) {
            $paths[] = self::packagePath($release);
        }
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

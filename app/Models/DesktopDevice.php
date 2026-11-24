<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * A paired Windows desktop notifier. Pairing is a loopback + PKCE exchange:
 * the signed-in user approves in the browser, the app receives a one-time code
 * on 127.0.0.1 and swaps it (with its verifier) for a bearer token. Only
 * SHA-256 hashes of codes and tokens are stored.
 */
final class DesktopDevice
{
    private const CODE_TTL_SECONDS = 120;
    private const TOUCH_EVERY_SECONDS = 300;

    /** The site keeps working until an admin runs the migration. */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $pdo = Database::pdo();
            $ready = (bool) $pdo->query("SHOW TABLES LIKE 'desktop_devices'")->fetchColumn()
                && (bool) $pdo->query("SHOW TABLES LIKE 'desktop_pair_codes'")->fetchColumn()
                && (bool) $pdo->query("SHOW TABLES LIKE 'user_notifications'")->fetchColumn();
        }
        return $ready;
    }

    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        return mb_substr($name !== '' ? $name : 'Windows', 0, 80);
    }

    public static function issueCode(int $userId, string $challenge, string $deviceName): string
    {
        $pdo = Database::pdo();
        $pdo->exec('DELETE FROM desktop_pair_codes WHERE expires_at < NOW()');
        $code = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO desktop_pair_codes (code_hash, user_id, challenge, device_name, expires_at)
             VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([hash('sha256', $code), $userId, $challenge, self::cleanName($deviceName), self::CODE_TTL_SECONDS]);
        return $code;
    }

    /**
     * Spend a code. It is consumed before the verifier is checked, so a wrong
     * verifier burns it rather than allowing another guess.
     *
     * @return array{user_id:int,device_name:string}|null
     */
    public static function consumeCode(string $code, string $verifier): ?array
    {
        $hash = hash('sha256', $code);
        $pdo = Database::pdo();
        $st = $pdo->prepare('UPDATE desktop_pair_codes SET used_at = NOW() WHERE code_hash = ? AND used_at IS NULL AND expires_at >= NOW()');
        $st->execute([$hash]);
        if ($st->rowCount() !== 1) {
            return null;
        }
        $st = $pdo->prepare('SELECT user_id, challenge, device_name FROM desktop_pair_codes WHERE code_hash = ?');
        $st->execute([$hash]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $row['challenge'], $expected)) {
            return null;
        }
        return ['user_id' => (int) $row['user_id'], 'device_name' => (string) $row['device_name']];
    }

    /** @return array{id:int,token:string} the token is only ever available here */
    public static function create(int $userId, string $name): array
    {
        $token = 'edt_' . bin2hex(random_bytes(32));
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO desktop_devices (user_id, name, token_hash, last_seen_at) VALUES (?,?,?, NOW())')
            ->execute([$userId, self::cleanName($name), hash('sha256', $token)]);
        return ['id' => (int) $pdo->lastInsertId(), 'token' => $token];
    }

    /** @return array{device:array,user:array}|null active device of an active user */
    public static function authenticate(string $token): ?array
    {
        if (!preg_match('/^edt_[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM desktop_devices WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1'
        );
        $st->execute([hash('sha256', $token)]);
        $device = $st->fetch();
        if (!$device) {
            return null;
        }
        $user = User::findById((int) $device['user_id']);
        if (!$user || (int) $user['is_active'] !== 1) {
            return null;
        }
        return ['device' => $device, 'user' => $user];
    }

    /** Written at most every few minutes, so polling doesn't mean a write per request. */
    public static function touch(array $device, ?string $appVersion): void
    {
        $version = $appVersion !== null && preg_match('/^[0-9A-Za-z.+-]{1,32}$/', $appVersion) ? $appVersion : $device['app_version'];
        $last = $device['last_seen_at'] ? strtotime((string) $device['last_seen_at']) : 0;
        if ($version === $device['app_version'] && time() - $last < self::TOUCH_EVERY_SECONDS) {
            return;
        }
        Database::pdo()->prepare('UPDATE desktop_devices SET last_seen_at = NOW(), app_version = ? WHERE id = ?')
            ->execute([$version, $device['id']]);
    }

    /** @return array<int,array> */
    public static function activeFor(int $userId): array
    {
        if (!self::ready()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, app_version, created_at, last_seen_at FROM desktop_devices
             WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC, id DESC'
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    public static function hasActive(int $userId): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM desktop_devices WHERE user_id = ? AND revoked_at IS NULL LIMIT 1');
        $st->execute([$userId]);
        return (bool) $st->fetchColumn();
    }

    public static function revoke(int $id, int $userId): bool
    {
        $st = Database::pdo()->prepare('UPDATE desktop_devices SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
        $st->execute([$id, $userId]);
        return $st->rowCount() === 1;
    }
}

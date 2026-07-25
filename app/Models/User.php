<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public static function findById(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $u = $st->fetch();
        return $u ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        $u = $st->fetch();
        return $u ?: null;
    }

    /** @return string[] role codes */
    public static function rolesFor(int $userId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.hierarchy_level IS NULL, r.hierarchy_level'
        );
        $st->execute([$userId]);
        return array_column($st->fetchAll(), 'code');
    }

    /** @return array<int,array> all active users with their role codes, for the impersonation picker */
    public static function allWithRoles(): array
    {
        $users = Database::pdo()->query('SELECT * FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
        foreach ($users as &$u) {
            $u['roles'] = self::rolesFor((int) $u['id']);
        }
        return $users;
    }

    public static function verifyPassword(array $user, string $password): bool
    {
        return password_verify($password, $user['password_hash']);
    }
}

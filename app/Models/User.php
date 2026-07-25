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

    /** Replace a user's full set of role assignments with the given role codes. */
    public static function setRoles(int $userId, array $roleCodes): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
            if ($roleCodes) {
                $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
                $ids = $pdo->prepare("SELECT id FROM roles WHERE code IN ({$placeholders})");
                $ids->execute($roleCodes);
                $ins = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                foreach ($ids->fetchAll(\PDO::FETCH_COLUMN) as $roleId) {
                    $ins->execute([$userId, $roleId]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Paginated, searchable, role-filterable user listing for the admin
     * user-management page.
     *
     * @param array{q?:string,role?:string} $filters
     * @return array{rows:array<int,array>,total:int}
     */
    public static function searchPaged(array $filters, int $page, int $perPage): array
    {
        $pdo = Database::pdo();
        $where = [];
        $params = [];

        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }

        $role = trim($filters['role'] ?? '');
        if ($role !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.code = ?)';
            $params[] = $role;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countSt = $pdo->prepare("SELECT COUNT(*) FROM users u {$whereSql}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT u.* FROM users u {$whereSql} ORDER BY u.full_name LIMIT {$perPage} OFFSET {$offset}";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['roles'] = self::rolesFor((int) $r['id']);
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage, 'perPage' => $perPage];
    }
}

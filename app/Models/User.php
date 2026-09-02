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

    /**
     * Find or create the local account for an Open Authenticator user object,
     * refreshing full_name / email / department from the gateway on every
     * login. Never touches created_at (an existing account may well predate
     * SSO), mirroring PeopleSync. Brand-new accounts get the bare `staff`
     * role, exactly like the RMS people-sync; re-login never changes roles.
     *
     * Matching order: (1) an account already linked by oa_user_id, then
     * (2) an unlinked account with the same email (adopted and linked), then
     * (3) a fresh account.
     *
     * @param array{id:int,email:string,first_name:string,last_name:string,department:string} $oa
     */
    public static function provisionFromOa(array $oa): ?array
    {
        $pdo = Database::pdo();
        $fullName = trim($oa['first_name'] . ' ' . $oa['last_name']);
        $email = $oa['email'] !== '' ? $oa['email'] : null;
        $dept  = $oa['department'] !== '' ? $oa['department'] : null;

        $st = $pdo->prepare('SELECT * FROM users WHERE oa_user_id = ? LIMIT 1');
        $st->execute([$oa['id']]);
        $user = $st->fetch() ?: null;

        if (!$user && $email !== null) {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND oa_user_id IS NULL LIMIT 1');
            $st->execute([$email]);
            $user = $st->fetch() ?: null;
        }

        if ($user) {
            $pdo->prepare(
                'UPDATE users SET oa_user_id = ?, full_name = ?, email = COALESCE(?, email), department = COALESCE(?, department) WHERE id = ?'
            )->execute([
                $oa['id'],
                $fullName !== '' ? $fullName : $user['full_name'],
                $email,
                $dept,
                $user['id'],
            ]);
            return self::findById((int) $user['id']);
        }

        // New account. username is NOT NULL UNIQUE and password_hash is NOT
        // NULL; an SSO-only user never password-logs-in, so the hash is random
        // and unusable.
        $username = 'oa:' . $oa['id'];
        $unusableHash = password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, department, oa_user_id, icon) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $username,
            $unusableHash,
            $fullName !== '' ? $fullName : $username,
            $email,
            $dept,
            $oa['id'],
            'person-workspace',
        ]);
        $userId = (int) $pdo->lastInsertId();

        $staffRoleId = Role::find('staff')['id'] ?? null;
        if ($staffRoleId !== null) {
            $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')
                ->execute([$userId, $staffRoleId]);
        }

        return self::findById($userId);
    }

    /**
     * Replace a user's full set of role assignments, together with the org
     * unit(s) each role is scoped to.
     *
     * @param string[] $roleCodes
     * @param array<string,int[]> $unitsByRole role code => org unit ids
     */
    public static function setRoles(int $userId, array $roleCodes, array $unitsByRole = []): void
    {
        $pdo = Database::pdo();
        $roles = Role::all();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_role_units WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);

            $insRole = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            foreach ($roleCodes as $code) {
                if (!isset($roles[$code])) {
                    continue;
                }
                $roleId = (int) $roles[$code]['id'];
                $insRole->execute([$userId, $roleId]);

                $unitType = Role::unitType($code);
                if ($unitType === null) {
                    continue;
                }
                $column = OrgUnit::column($unitType);
                $insUnit = $pdo->prepare(
                    "INSERT INTO user_role_units (user_id, role_id, {$column}) VALUES (?, ?, ?)"
                );
                foreach (array_unique($unitsByRole[$code] ?? []) as $unitId) {
                    $insUnit->execute([$userId, $roleId, (int) $unitId]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The org unit ids each of a user's roles is scoped to.
     *
     * @return array<string,int[]> role code => unit ids
     */
    public static function roleUnitsFor(int $userId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT r.code, uru.division_id, uru.work_id, uru.department_id
             FROM user_role_units uru
             JOIN roles r ON r.id = uru.role_id
             WHERE uru.user_id = ?'
        );
        $st->execute([$userId]);

        $out = [];
        foreach ($st->fetchAll() as $row) {
            $id = $row['division_id'] ?? $row['work_id'] ?? $row['department_id'];
            if ($id !== null) {
                $out[$row['code']][] = (int) $id;
            }
        }
        return $out;
    }

    /**
     * Same shape as roleUnitsFor() but with display names, for listings.
     *
     * @return array<string,string[]> role code => unit names
     */
    public static function roleUnitNamesFor(int $userId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT r.code, COALESCE(d.name, w.name, dp.name) AS unit_name
             FROM user_role_units uru
             JOIN roles r ON r.id = uru.role_id
             LEFT JOIN divisions d ON d.id = uru.division_id
             LEFT JOIN works w ON w.id = uru.work_id
             LEFT JOIN departments dp ON dp.id = uru.department_id
             WHERE uru.user_id = ?
             ORDER BY unit_name'
        );
        $st->execute([$userId]);

        $out = [];
        foreach ($st->fetchAll() as $row) {
            if ($row['unit_name'] !== null) {
                $out[$row['code']][] = (string) $row['unit_name'];
            }
        }
        return $out;
    }

    /** Effective due-date warning window: the user's own choice, else the admin default. */
    public static function warnDaysFor(array $user): int
    {
        $own = $user['notify_warn_days'] ?? null;
        if ($own !== null && (int) $own > 0) {
            return (int) $own;
        }
        return max(1, (int) Setting::get('notify_warn_days_default', '3'));
    }

    public static function setWarnDays(int $userId, ?int $days): void
    {
        Database::pdo()->prepare('UPDATE users SET notify_warn_days = ? WHERE id = ?')
            ->execute([$days, $userId]);
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
            $r['roleUnitNames'] = self::roleUnitNamesFor((int) $r['id']);
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage, 'perPage' => $perPage];
    }
}

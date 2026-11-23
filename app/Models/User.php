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
     * Find or create the local account for an RVC-ONE user object at SSO
     * login. Never changes is_active — only the User-sync knows a user's
     * gateway status. See upsertFromOa().
     *
     * @param array{id:int,email:string,first_name:string,last_name:string,department:string,national_id:string} $oa
     */
    public static function provisionFromOa(array $oa): ?array
    {
        return self::upsertFromOa($oa)['user'];
    }

    /**
     * Idempotent upsert of an RVC-ONE user object (from verify_token at login
     * or from the users.php sync API — both carry the same gateway user id),
     * refreshing full_name / email / department every time. Never touches
     * created_at (an existing account may well predate SSO), mirroring
     * PeopleSync. Brand-new accounts get the bare `staff` role, exactly like
     * the RMS people-sync; an existing account's roles are never changed.
     *
     * Matching order: (1) the account holding the user's national ID, the
     * identity key across RMS and RVC-ONE, then (2) an account already linked
     * by oa_user_id, then (3) an unlinked account with the same email and no
     * conflicting national ID, then (4) a fresh account. If (1) wins while a
     * different account holds this gateway id, that twin is merged in when it
     * has no national ID of its own, otherwise it just loses the link.
     *
     * $active: null leaves is_active alone (SSO login); true/false sets it
     * (User-sync: status active / suspended).
     *
     * @param array{id:int,email:string,first_name:string,last_name:string,department:string,national_id:string} $oa
     * @return array{user:?array,created:bool}
     */
    public static function upsertFromOa(array $oa, ?bool $active = null): array
    {
        $pdo = Database::pdo();
        $fullName = trim($oa['first_name'] . ' ' . $oa['last_name']);
        $email = $oa['email'] !== '' ? $oa['email'] : null;
        $dept  = $oa['department'] !== '' ? $oa['department'] : null;
        $nationalId = $oa['national_id'] ?? '';
        $user = self::matchOa($oa);

        if ($user) {
            self::resolveOaTwin($user, (int) $oa['id']);
            self::stampNationalId($user, $nationalId);
            $pdo->prepare(
                'UPDATE users SET oa_user_id = ?, full_name = ?, email = COALESCE(?, email), department = COALESCE(?, department), is_active = COALESCE(?, is_active) WHERE id = ?'
            )->execute([
                $oa['id'],
                $fullName !== '' ? $fullName : $user['full_name'],
                $email,
                $dept,
                $active === null ? null : (int) $active,
                $user['id'],
            ]);
            return ['user' => self::findById((int) $user['id']), 'created' => false];
        }

        // New account. username is NOT NULL UNIQUE: prefer the national ID
        // (same key the RMS people-sync uses, so a later RMS sync updates this
        // account instead of creating a twin), else a synthetic oa:{id}.
        // password_hash is NOT NULL; an SSO-only user never password-logs-in,
        // so the hash is random and unusable.
        $username = 'oa:' . $oa['id'];
        if ($nationalId !== '' && self::findByUsername($nationalId) === null) {
            $username = $nationalId;
        }
        $unusableHash = password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, email, department, oa_user_id, icon, is_active) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $username,
            $unusableHash,
            $fullName !== '' ? $fullName : $username,
            $email,
            $dept,
            $oa['id'],
            'person-workspace',
            $active === false ? 0 : 1,
        ]);
        $userId = (int) $pdo->lastInsertId();
        self::stampNationalId(self::findById($userId) ?? [], $nationalId);

        $staffRoleId = Role::find('staff')['id'] ?? null;
        if ($staffRoleId !== null) {
            $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')
                ->execute([$userId, $staffRoleId]);
        }

        return ['user' => self::findById($userId), 'created' => true];
    }

    /**
     * The local account an RVC-ONE user object maps to, if any — see the
     * matching order on upsertFromOa().
     *
     * @param array{id:int,email:string,national_id?:string} $oa
     */
    public static function matchOa(array $oa): ?array
    {
        $pdo = Database::pdo();
        $rawId = (string) ($oa['national_id'] ?? '');
        $nationalId = self::normalizeNationalId($rawId);

        if ($nationalId !== '' && ($user = self::findByNationalId($nationalId))) {
            return $user;
        }

        $st = $pdo->prepare('SELECT * FROM users WHERE oa_user_id = ? LIMIT 1');
        $st->execute([$oa['id']]);
        if ($user = $st->fetch()) {
            return $user;
        }

        // RMS people_id values that aren't 13 digits still key an account by username.
        if ($rawId !== '' && $nationalId === '') {
            $st = $pdo->prepare('SELECT * FROM users WHERE username = ? AND oa_user_id IS NULL LIMIT 1');
            $st->execute([$rawId]);
            if ($user = $st->fetch()) {
                return $user;
            }
        }

        if ($oa['email'] !== '') {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND oa_user_id IS NULL ORDER BY id');
            $st->execute([$oa['email']]);
            foreach ($st->fetchAll() as $candidate) {
                $held = self::nationalIdOf($candidate);
                if ($nationalId === '' || $held === '' || $held === $nationalId) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** Whether migration 20261123_add_user_national_id.sql has run. */
    public static function hasNationalIdColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = (bool) Database::pdo()->query("SHOW COLUMNS FROM users LIKE 'national_id'")->fetchColumn();
        }
        return $has;
    }

    /** Digits only, and only a full 13-digit ID counts; anything else is ''. */
    public static function normalizeNationalId(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        return strlen($digits) === 13 ? $digits : '';
    }

    /** The national ID an account holds: its column, else a 13-digit username. */
    public static function nationalIdOf(array $user): string
    {
        $stored = self::normalizeNationalId((string) ($user['national_id'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        $username = (string) ($user['username'] ?? '');
        return preg_match('/^\d{13}$/', $username) ? $username : '';
    }

    public static function findByNationalId(string $nationalId): ?array
    {
        if (self::hasNationalIdColumn()) {
            $st = Database::pdo()->prepare(
                'SELECT * FROM users WHERE national_id = ? OR username = ? ORDER BY (national_id <=> ?) DESC, id LIMIT 1'
            );
            $st->execute([$nationalId, $nationalId, $nationalId]);
        } else {
            $st = Database::pdo()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $st->execute([$nationalId]);
        }
        return $st->fetch() ?: null;
    }

    /**
     * Record a national ID on an account that has none, and replace a
     * synthetic oa:{id} username with it so the RMS sync lands here too.
     * An account already holding a different ID is left alone.
     */
    public static function stampNationalId(array $user, string $nationalId): void
    {
        $nationalId = self::normalizeNationalId($nationalId);
        if ($nationalId === '' || empty($user['id'])) {
            return;
        }
        $held = self::nationalIdOf($user);
        if ($held !== '' && $held !== $nationalId) {
            return;
        }
        $other = self::findByNationalId($nationalId);
        if ($other && (int) $other['id'] !== (int) $user['id']) {
            return;
        }

        $set = ["username = IF(username LIKE 'oa:%', ?, username)"];
        $params = [$nationalId];
        if (self::hasNationalIdColumn()) {
            $set[] = 'national_id = ?';
            $params[] = $nationalId;
        }
        $params[] = (int) $user['id'];
        Database::pdo()->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    }

    /** Clear the way for $user to take this gateway id. See upsertFromOa(). */
    private static function resolveOaTwin(array $user, int $oaId): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM users WHERE oa_user_id = ? AND id <> ? LIMIT 1');
        $st->execute([$oaId, $user['id']]);
        $twin = $st->fetch();
        if (!$twin) {
            return;
        }
        if (self::nationalIdOf($twin) !== '') {
            $pdo->prepare('UPDATE users SET oa_user_id = NULL WHERE id = ?')->execute([$twin['id']]);
            return;
        }
        UserMerge::merge((int) $twin['id'], (int) $user['id']);
        AuditLog::record((int) $user['id'], null, 'user.merge_auto', 'user', (int) $user['id'], 'merged user #' . (int) $twin['id']);
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

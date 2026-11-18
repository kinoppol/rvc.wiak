<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The three kinds of organisational unit (ฝ่าย / งาน / แผนก) behind one
 * small CRUD surface. `TYPES` is the only place table and column names
 * come from — callers pass a type key, never a table name, so a type
 * coming off a request can never reach SQL unvalidated.
 */
final class OrgUnit
{
    public const TYPES = [
        'division' => [
            'table' => 'divisions',
            'column' => 'division_id',
            'label' => 'ฝ่าย',
            'icon' => 'diagram-2',
        ],
        'work' => [
            'table' => 'works',
            'column' => 'work_id',
            'label' => 'งาน',
            'icon' => 'briefcase',
        ],
        'department' => [
            'table' => 'departments',
            'column' => 'department_id',
            'label' => 'แผนก',
            'icon' => 'buildings',
        ],
    ];

    /**
     * Whether this unit type carries a parent ฝ่าย. งาน always does. แผนก
     * does once migration 20261118_department_division.sql has run — checked
     * at runtime because a push deploys before an admin runs the migration.
     */
    public static function hasDivision(string $type): bool
    {
        if ($type === 'work') {
            return true;
        }
        if ($type !== 'department') {
            return false;
        }
        static $deptHas = null;
        if ($deptHas === null) {
            $deptHas = (bool) Database::pdo()->query("SHOW COLUMNS FROM departments LIKE 'division_id'")->fetchColumn();
        }
        return $deptHas;
    }

    public static function isType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    public static function column(string $type): string
    {
        return self::TYPES[$type]['column'];
    }

    /** @return array<int,array> */
    public static function all(string $type): array
    {
        $t = self::TYPES[$type]['table'];
        if (self::hasDivision($type)) {
            return Database::pdo()->query(
                "SELECT x.*, d.name AS division_name FROM {$t} x
                 LEFT JOIN divisions d ON d.id = x.division_id
                 ORDER BY d.name IS NULL, d.name, x.name"
            )->fetchAll();
        }
        return Database::pdo()->query("SELECT * FROM {$t} ORDER BY name")->fetchAll();
    }

    public static function find(string $type, int $id): ?array
    {
        $t = self::TYPES[$type]['table'];
        $st = Database::pdo()->prepare("SELECT * FROM {$t} WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function create(string $type, string $name, ?int $divisionId = null): void
    {
        $t = self::TYPES[$type]['table'];
        if (self::hasDivision($type)) {
            Database::pdo()->prepare("INSERT INTO {$t} (name, division_id) VALUES (?, ?)")
                ->execute([$name, $divisionId]);
            return;
        }
        Database::pdo()->prepare("INSERT INTO {$t} (name) VALUES (?)")->execute([$name]);
    }

    public static function update(string $type, int $id, string $name, ?int $divisionId = null): void
    {
        $t = self::TYPES[$type]['table'];
        if (self::hasDivision($type)) {
            Database::pdo()->prepare("UPDATE {$t} SET name = ?, division_id = ? WHERE id = ?")
                ->execute([$name, $divisionId, $id]);
            return;
        }
        Database::pdo()->prepare("UPDATE {$t} SET name = ? WHERE id = ?")->execute([$name, $id]);
    }

    /** Set a unit's ฝ่าย only if it has none yet (an admin's choice is kept). */
    public static function fillDivision(string $type, int $id, ?int $divisionId): void
    {
        if ($divisionId === null || !self::hasDivision($type)) {
            return;
        }
        $t = self::TYPES[$type]['table'];
        Database::pdo()->prepare("UPDATE {$t} SET division_id = ? WHERE id = ? AND division_id IS NULL")
            ->execute([$divisionId, $id]);
    }

    /**
     * People per unit (distinct users holding any role in it), all units of
     * one type in one query: unit id => count.
     *
     * @return array<int,int>
     */
    public static function memberCounts(string $type): array
    {
        $col = self::TYPES[$type]['column'];
        $out = [];
        foreach (Database::pdo()->query(
            "SELECT {$col} AS id, COUNT(DISTINCT user_id) AS n FROM user_role_units WHERE {$col} IS NOT NULL GROUP BY {$col}"
        ) as $r) {
            $out[(int) $r['id']] = (int) $r['n'];
        }
        return $out;
    }

    /** How many users are currently assigned a role scoped to this unit. */
    public static function usageCount(string $type, int $id): int
    {
        $col = self::TYPES[$type]['column'];
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM user_role_units WHERE {$col} = ?");
        $st->execute([$id]);
        return (int) $st->fetchColumn();
    }

    /**
     * All org units the given user holds any role in, deduplicated by type+id.
     * Director/admin have no unit rows and will get an empty array.
     *
     * @return array<int,array{type:string,id:int,name:string}>
     */
    public static function unitsForUser(int $userId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT u.division_id, u.work_id, u.department_id,
                    d.name AS division_name, w.name AS work_name, dep.name AS department_name
             FROM user_role_units u
             LEFT JOIN divisions d   ON d.id   = u.division_id
             LEFT JOIN works w       ON w.id   = u.work_id
             LEFT JOIN departments dep ON dep.id = u.department_id
             WHERE u.user_id = ?'
        );
        $st->execute([$userId]);

        $seen = [];
        $units = [];
        foreach ($st->fetchAll() as $row) {
            foreach (['work' => 'work_id', 'department' => 'department_id', 'division' => 'division_id'] as $type => $col) {
                if (!$row[$col]) {
                    continue;
                }
                $key = $type . ':' . $row[$col];
                if (!isset($seen[$key])) {
                    $nameCol = match($type) {
                        'work'       => 'work_name',
                        'department' => 'department_name',
                        'division'   => 'division_name',
                    };
                    $units[] = ['type' => $type, 'id' => (int) $row[$col], 'name' => $row[$nameCol]];
                    $seen[$key] = true;
                }
            }
        }
        return $units;
    }

    /**
     * Deleting a unit that people are still assigned to would silently strip
     * those role assignments (the FK cascades), so refuse instead and let the
     * admin reassign first.
     */
    public static function delete(string $type, int $id): bool
    {
        if (self::usageCount($type, $id) > 0) {
            return false;
        }
        $t = self::TYPES[$type]['table'];
        Database::pdo()->prepare("DELETE FROM {$t} WHERE id = ?")->execute([$id]);
        return true;
    }
}

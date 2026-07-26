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
        if ($type === 'work') {
            return Database::pdo()->query(
                'SELECT w.*, d.name AS division_name FROM works w
                 LEFT JOIN divisions d ON d.id = w.division_id
                 ORDER BY d.name IS NULL, d.name, w.name'
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
        if ($type === 'work') {
            Database::pdo()->prepare('INSERT INTO works (name, division_id) VALUES (?, ?)')
                ->execute([$name, $divisionId]);
            return;
        }
        Database::pdo()->prepare("INSERT INTO {$t} (name) VALUES (?)")->execute([$name]);
    }

    public static function update(string $type, int $id, string $name, ?int $divisionId = null): void
    {
        $t = self::TYPES[$type]['table'];
        if ($type === 'work') {
            Database::pdo()->prepare('UPDATE works SET name = ?, division_id = ? WHERE id = ?')
                ->execute([$name, $divisionId, $id]);
            return;
        }
        Database::pdo()->prepare("UPDATE {$t} SET name = ? WHERE id = ?")->execute([$name, $id]);
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

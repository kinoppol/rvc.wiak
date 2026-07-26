<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Role
{
    /**
     * Which kind of org unit a role must be scoped to. Roles absent from
     * this map (admin, director) sit outside the org chart and take none.
     */
    public const ROLE_UNIT_TYPE = [
        'deputy' => 'division',
        'supervisor' => 'work',
        'dept' => 'department',
        'staff' => 'work',
        'teacher' => 'department',
    ];

    /** Roles that take exactly one unit rather than one-or-more. */
    public const SINGLE_UNIT_ROLES = ['deputy'];

    public static function unitType(string $code): ?string
    {
        return self::ROLE_UNIT_TYPE[$code] ?? null;
    }

    public static function needsSingleUnit(string $code): bool
    {
        return in_array($code, self::SINGLE_UNIT_ROLES, true);
    }

    /** @return array<int,array> keyed by code */
    public static function all(): array
    {
        static $cache = null;
        if ($cache === null) {
            $rows = Database::pdo()->query('SELECT * FROM roles ORDER BY hierarchy_level IS NULL, hierarchy_level')->fetchAll();
            $cache = [];
            foreach ($rows as $r) {
                $cache[$r['code']] = $r;
            }
        }
        return $cache;
    }

    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    public static function isAssigner(string $code): bool
    {
        $r = self::find($code);
        return $r ? (bool) $r['is_assigner'] : false;
    }

    public static function level(string $code): ?int
    {
        $r = self::find($code);
        return $r && $r['hierarchy_level'] !== null ? (int) $r['hierarchy_level'] : null;
    }

    /**
     * Cross-level assignment: from and to roles are both in the chain of
     * command and the assignment skips at least one level (e.g. director
     * straight to staff instead of via deputy/dept -> supervisor).
     */
    public static function isCrossLevel(string $fromCode, string $toCode): bool
    {
        $a = self::level($fromCode);
        $b = self::level($toCode);
        if ($a === null || $b === null) {
            return $fromCode !== $toCode && ($fromCode === 'admin' || $toCode === 'admin') === false;
        }
        return ($b - $a) > 1 || ($b - $a) < 0;
    }
}

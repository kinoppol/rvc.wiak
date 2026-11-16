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
        'asst_supervisor' => 'work',
        'work_teacher' => 'work',
        'dept' => 'department',
        'staff' => 'work',
        'teacher' => 'department',
    ];

    /**
     * Hierarchy levels that are an optional tier: an assignment that skips
     * only these is not cross-level. Level 4 holds ผู้ช่วยหัวหน้างาน and
     * ครูช่วยงาน, which not every งาน has, so หัวหน้างาน (3) → เจ้าหน้าที่ (5)
     * is still a normal step.
     */
    public const OPTIONAL_LEVELS = [4];

    /**
     * Roles that take exactly one unit rather than one-or-more. Empty since
     * the real RMS org chart has one รองผอ. overseeing two ฝ่าย — deputy
     * used to be limited to one.
     */
    public const SINGLE_UNIT_ROLES = [];

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
     * straight to staff instead of via deputy/dept -> supervisor). Skipping
     * only an OPTIONAL_LEVELS tier doesn't count.
     */
    public static function isCrossLevel(string $fromCode, string $toCode): bool
    {
        $a = self::level($fromCode);
        $b = self::level($toCode);
        if ($a === null || $b === null) {
            return $fromCode !== $toCode && ($fromCode === 'admin' || $toCode === 'admin') === false;
        }
        if ($b < $a) {
            return true; // upward
        }
        for ($lvl = $a + 1; $lvl < $b; $lvl++) {
            if (!in_array($lvl, self::OPTIONAL_LEVELS, true)) {
                return true;
            }
        }
        return false;
    }
}

<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data for the autocomplete fields on the ticket forms ([data-combo] in
 * app.js): people to assign to, and unit names to put in "หน่วยงาน".
 */
final class Picker
{
    /**
     * Active users as {v: id, label: name, sub: "roles · units", units: [names],
     * rank} — a handful of queries in total, not one per user.
     *
     * `rank` places people under the viewer's chain of command first (2 =
     * directly under the viewer's active role, 1 = further down that line,
     * 0 = anyone else). app.js adds a bonus for people in the unit typed in the
     * form's "หน่วยงาน" field, then sorts by that score before name.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function people(?int $excludeId = null, ?int $viewerId = null, ?string $viewerRole = null): array
    {
        $pdo = Database::pdo();
        $users = $pdo->query('SELECT id, full_name, department FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();

        $roles = [];
        $codes = [];
        foreach ($pdo->query(
            'SELECT ur.user_id, r.code, r.short_label FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             ORDER BY r.hierarchy_level IS NULL, r.hierarchy_level'
        ) as $r) {
            $roles[(int) $r['user_id']][] = $r['short_label'];
            $codes[(int) $r['user_id']][$r['code']] = true;
        }

        // Every scoped role assignment, with the ฝ่าย its งาน/แผนก sits under.
        $deptDiv = OrgUnit::hasDivision('department') ? 'dep.division_id' : 'NULL';
        $assign = $pdo->query(
            "SELECT uru.user_id, r.code, uru.division_id, uru.work_id, uru.department_id,
                    w.division_id AS work_div, {$deptDiv} AS dept_div,
                    COALESCE(d.name, w.name, dep.name) AS name
             FROM user_role_units uru
             JOIN roles r ON r.id = uru.role_id
             LEFT JOIN divisions d ON d.id = uru.division_id
             LEFT JOIN works w ON w.id = uru.work_id
             LEFT JOIN departments dep ON dep.id = uru.department_id"
        )->fetchAll();

        $units = [];
        $mine = ['division' => [], 'work' => [], 'department' => []];
        foreach ($assign as $a) {
            $uid = (int) $a['user_id'];
            if ($a['name'] !== null) {
                $units[$uid][$a['name']] = true;
            }
            if ($viewerId !== null && $uid === $viewerId && $a['code'] === $viewerRole) {
                foreach (['division', 'work', 'department'] as $k) {
                    if ($a[$k . '_id'] !== null) {
                        $mine[$k][(int) $a[$k . '_id']] = true;
                    }
                }
            }
        }

        $rank = [];
        $bump = function (int $uid, int $r) use (&$rank) {
            $rank[$uid] = max($rank[$uid] ?? 0, $r);
        };
        if ($viewerRole === 'director') {
            foreach ($codes as $uid => $c) {
                if (isset($c['deputy'])) {
                    $bump($uid, 2);
                } elseif (array_intersect_key($c, array_flip(['supervisor', 'dept', 'staff', 'teacher']))) {
                    $bump($uid, 1);
                }
            }
        } else {
            foreach ($assign as $a) {
                $uid = (int) $a['user_id'];
                $work = $a['work_id'] !== null ? (int) $a['work_id'] : null;
                $dept = $a['department_id'] !== null ? (int) $a['department_id'] : null;
                if ($viewerRole === 'deputy') {
                    $inDiv = ($work !== null && $a['work_div'] !== null && isset($mine['division'][(int) $a['work_div']]))
                        || ($dept !== null && $a['dept_div'] !== null && isset($mine['division'][(int) $a['dept_div']]));
                    if ($inDiv) {
                        $bump($uid, in_array($a['code'], ['supervisor', 'dept'], true) ? 2 : 1);
                    }
                } elseif ($viewerRole === 'supervisor') {
                    if ($a['code'] === 'staff' && $work !== null && isset($mine['work'][$work])) {
                        $bump($uid, 2);
                    }
                } elseif ($viewerRole === 'dept') {
                    if ($a['code'] === 'teacher' && $dept !== null && isset($mine['department'][$dept])) {
                        $bump($uid, 2);
                    }
                }
            }
        }

        $out = [];
        foreach ($users as $u) {
            $id = (int) $u['id'];
            if ($id === $excludeId) {
                continue;
            }
            $parts = [];
            if (!empty($roles[$id])) {
                $parts[] = implode(', ', array_unique($roles[$id]));
            }
            if (!empty($units[$id])) {
                $parts[] = implode(', ', array_keys($units[$id]));
            } elseif (!empty($u['department'])) {
                $parts[] = $u['department'];
            }
            $out[] = [
                'v' => (string) $id,
                'label' => (string) $u['full_name'],
                'sub' => implode(' · ', $parts),
                'units' => array_keys($units[$id] ?? []),
                'rank' => $rank[$id] ?? 0,
            ];
        }
        // Server order already matches the no-context order in app.js.
        usort($out, fn($a, $b) => $b['rank'] <=> $a['rank']);
        return $out;
    }

    /**
     * Suggestions for "หน่วยงาน / หมายเหตุสั้น": every ฝ่าย / งาน / แผนก, then
     * values typed on recent tickets.
     *
     * @return array<int,array{v:string,label:string,sub:string}>
     */
    public static function units(): array
    {
        $out = [];
        $seen = [];
        foreach (['work', 'department', 'division'] as $type) {
            foreach (OrgUnit::all($type) as $u) {
                $name = (string) $u['name'];
                if (!isset($seen[$name])) {
                    $seen[$name] = true;
                    $out[] = ['v' => $name, 'label' => $name, 'sub' => OrgUnit::label($type)];
                }
            }
        }
        $recent = Database::pdo()->query(
            "SELECT meta FROM tickets WHERE meta IS NOT NULL AND meta <> '' GROUP BY meta ORDER BY MAX(created_at) DESC LIMIT 100"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($recent as $m) {
            if (!isset($seen[$m])) {
                $seen[$m] = true;
                $out[] = ['v' => (string) $m, 'label' => (string) $m, 'sub' => 'เคยใช้'];
            }
        }
        return $out;
    }
}

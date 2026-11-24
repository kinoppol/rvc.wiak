<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\RmsOrgChartParser;
use RuntimeException;

/**
 * Finds accounts that belong to one person and folds them into one.
 *
 * The national ID decides identity. Two accounts with different IDs are never
 * merged, whatever their names. A name match only proposes a merge of an
 * account with no ID into the single same-named account that has one.
 */
final class UserMerge
{
    /**
     * Move everything from $sourceId onto $targetId and delete the source.
     * References are read from the schema so a future table can't be missed.
     */
    public static function merge(int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new RuntimeException('ไม่สามารถรวมบัญชีเข้ากับตัวเองได้');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM users WHERE id IN (?, ?) FOR UPDATE');
            $st->execute([$sourceId, $targetId]);
            $rows = array_column($st->fetchAll(), null, 'id');
            $source = $rows[$sourceId] ?? null;
            $target = $rows[$targetId] ?? null;
            if (!$source || !$target) {
                throw new RuntimeException('ไม่พบบัญชีที่ต้องการรวม');
            }

            $sourceNid = User::nationalIdOf($source);
            $targetNid = User::nationalIdOf($target);
            if ($sourceNid !== '' && $targetNid !== '' && $sourceNid !== $targetNid) {
                throw new RuntimeException('บัญชีทั้งสองมีเลขประจำตัวประชาชนต่างกัน จึงรวมกันไม่ได้');
            }

            self::moveRoles($sourceId, $targetId);
            self::moveReferences($sourceId, $targetId);

            // Delete first: oa_user_id and national_id are UNIQUE.
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$sourceId]);
            $pdo->prepare(
                'UPDATE users SET email = COALESCE(email, ?), department = COALESCE(department, ?),
                    avatar_path = COALESCE(avatar_path, ?), notify_warn_days = COALESCE(notify_warn_days, ?),
                    oa_user_id = COALESCE(oa_user_id, ?)
                 WHERE id = ?'
            )->execute([
                $source['email'], $source['department'], $source['avatar_path'],
                $source['notify_warn_days'], $source['oa_user_id'], $targetId,
            ]);
            if ($targetNid === '' && $sourceNid !== '') {
                User::stampNationalId($target, $sourceNid);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Union the roles and their units. A scoped role the source holds with no
     * unit is only the sync's bare default, so it isn't carried over unless
     * the target would otherwise have no role at all.
     */
    private static function moveRoles(int $sourceId, int $targetId): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ur.role_id, r.code,
                    EXISTS (SELECT 1 FROM user_role_units u WHERE u.user_id = ur.user_id AND u.role_id = ur.role_id) AS has_units
             FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?'
        );
        $st->execute([$sourceId]);
        $sourceRoles = $st->fetchAll();

        $count = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id = ?');
        $count->execute([$targetId]);
        $targetHasRoles = (int) $count->fetchColumn() > 0;

        $real = array_filter($sourceRoles, static fn ($r) => Role::unitType($r['code']) === null || (int) $r['has_units'] === 1);
        $carry = ($real || $targetHasRoles) ? $real : $sourceRoles;

        $ins = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
        foreach ($carry as $r) {
            $ins->execute([$targetId, $r['role_id']]);
        }

        // The UNIQUE key doesn't dedupe here: its unit columns are mostly NULL.
        $pdo->prepare(
            'INSERT INTO user_role_units (user_id, role_id, division_id, work_id, department_id)
             SELECT ?, s.role_id, s.division_id, s.work_id, s.department_id
             FROM user_role_units s
             WHERE s.user_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM user_role_units t
                   WHERE t.user_id = ? AND t.role_id = s.role_id
                     AND t.division_id <=> s.division_id
                     AND t.work_id <=> s.work_id
                     AND t.department_id <=> s.department_id
               )'
        )->execute([$targetId, $sourceId, $targetId]);
    }

    private static function moveReferences(int $sourceId, int $targetId): void
    {
        $pdo = Database::pdo();
        $refs = $pdo->query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id'"
        )->fetchAll();

        foreach ($refs as $ref) {
            if (in_array($ref['t'], ['user_roles', 'user_role_units'], true)) {
                continue; // handled by moveRoles(); the rest cascade on delete
            }
            // Both accounts may hold the same alert (UNIQUE user + dedupe key):
            // keep the target's, and the source's copy cascades on delete.
            $ignore = $ref['t'] === 'user_notifications' ? 'IGNORE ' : '';
            $pdo->prepare("UPDATE {$ignore}`{$ref['t']}` SET `{$ref['c']}` = ? WHERE `{$ref['c']}` = ?")
                ->execute([$targetId, $sourceId]);
        }

        $pdo->prepare("UPDATE audit_log SET entity_id = ? WHERE entity_type = 'user' AND entity_id = ?")
            ->execute([$targetId, $sourceId]);
        if ($pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn()) {
            $pdo->prepare('UPDATE schema_migrations SET applied_by = ? WHERE applied_by = ?')
                ->execute([$targetId, $sourceId]);
        }
    }

    /**
     * Same-name groups. `sources` can be merged into `target` as they are.
     * `blocked` are accounts linked to a different RVC-ONE id than the target,
     * which may be another person. `review` groups have no single ID holder.
     *
     * @return array{merge:array<int,array>,review:array<int,array>}
     */
    public static function findDuplicates(): array
    {
        $users = Database::pdo()->query('SELECT * FROM users ORDER BY id')->fetchAll();
        $groups = [];
        foreach ($users as $u) {
            $key = RmsOrgChartParser::normalizeName((string) $u['full_name']);
            if ($key !== '') {
                $u['nid'] = User::nationalIdOf($u);
                $groups[$key][] = $u;
            }
        }

        $out = ['merge' => [], 'review' => []];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $holders = array_values(array_filter($group, static fn ($u) => $u['nid'] !== ''));
            if (count($holders) !== 1) {
                $out['review'][] = [
                    'users' => $group,
                    'reason' => $holders ? 'เลขประจำตัวประชาชนต่างกัน น่าจะเป็นคนละคนที่ชื่อเหมือนกัน' : 'ยังไม่มีบัญชีใดมีเลขประจำตัวประชาชน',
                ];
                continue;
            }

            $target = $holders[0];
            $linked = $target['oa_user_id'] !== null ? (int) $target['oa_user_id'] : null;
            $sources = $blocked = [];
            foreach ($group as $u) {
                if ((int) $u['id'] === (int) $target['id']) {
                    continue;
                }
                $oa = $u['oa_user_id'] !== null ? (int) $u['oa_user_id'] : null;
                if ($oa !== null && $linked !== null && $oa !== $linked) {
                    $blocked[] = $u;
                    continue;
                }
                $linked ??= $oa;
                $sources[] = $u;
            }
            $out['merge'][] = ['target' => $target, 'sources' => $sources, 'blocked' => $blocked];
        }
        return $out;
    }

    /** The account $sourceId may be merged into, per findDuplicates(). */
    public static function targetFor(int $sourceId, bool $includeBlocked): ?int
    {
        foreach (self::findDuplicates()['merge'] as $g) {
            $pool = $includeBlocked ? array_merge($g['sources'], $g['blocked']) : $g['sources'];
            if (in_array($sourceId, array_map('intval', array_column($pool, 'id')), true)) {
                return (int) $g['target']['id'];
            }
        }
        return null;
    }

    /**
     * Merge every safe pair. $afterEach runs per merge, so a failure part-way
     * still leaves the finished merges audited.
     *
     * @param callable(int,int):void $afterEach source id, target id
     */
    public static function mergeAllSafe(callable $afterEach): int
    {
        $count = 0;
        foreach (self::findDuplicates()['merge'] as $g) {
            foreach ($g['sources'] as $s) {
                self::merge((int) $s['id'], (int) $g['target']['id']);
                $afterEach((int) $s['id'], (int) $g['target']['id']);
                $count++;
            }
        }
        return $count;
    }

    /** Last four digits only, for telling accounts apart on screen. */
    public static function maskNationalId(string $nid): string
    {
        return $nid === '' ? '—' : '•••••••••' . substr($nid, -4);
    }
}

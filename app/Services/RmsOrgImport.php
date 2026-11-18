<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use RuntimeException;

/**
 * Imports the staff structure published on RMS (pms_graph.php): creates the
 * ฝ่าย / งาน / แผนก that don't exist yet and gives each matched person the
 * matching role scoped to their unit.
 *
 * Two steps so an admin sees the effect before anything is written:
 *   plan()  — fetch + parse + work out what would change (read-only),
 *   apply() — re-fetch, check the page still matches what was previewed
 *             (same fingerprint), then write.
 *
 * The import is additive: it adds roles/units, it never removes a role
 * an admin assigned by hand. The one exception is the bare, unit-less
 * `staff` role every synced account starts with — it's dropped once the
 * person gets a real role from the chart, otherwise they'd keep showing
 * as "ยังไม่ระบุสังกัด".
 *
 * The page carries no national ID, so people are matched to accounts by
 * name (honorifics stripped). Names that match no account, or more than
 * one, are listed and skipped — the import never creates accounts.
 */
final class RmsOrgImport
{
    public const URL_SETTING = 'rms_org_chart_url';
    public const DEFAULT_URL = 'https://rms.rvc.ac.th/pms_graph.php';
    public const LAST_RUN_KEY = 'rms_org_import_last_run_at';

    /** Units whose name matches this are RMS leftovers marked for deletion. */
    private const RETIRED_PATTERN = '/\(\s*สำหรับลบ\s*\)/u';

    public static function url(): string
    {
        $u = trim((string) Setting::get(self::URL_SETTING, ''));
        return $u !== '' ? $u : self::DEFAULT_URL;
    }

    /** Fetch the page and return it as UTF-8 HTML. */
    public static function fetch(string $url): string
    {
        $p = parse_url($url);
        if (empty($p['scheme']) || empty($p['host']) || !in_array($p['scheme'], ['http', 'https'], true)) {
            throw new RuntimeException('URL ไม่ถูกต้อง');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('เซิร์ฟเวอร์ไม่มี PHP curl');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'EduTask-OrgImport/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            throw new RuntimeException('ดึงหน้าเว็บไม่สำเร็จ' . ($err !== '' ? ": {$err}" : ''));
        }
        if ($code >= 400) {
            throw new RuntimeException("ดึงหน้าเว็บไม่สำเร็จ (HTTP {$code})");
        }
        return self::toUtf8((string) $body, $type);
    }

    /**
     * RMS serves TIS-620 / windows-874. Decide from the Content-Type header,
     * then the <meta> tag; if the bytes are already valid UTF-8 keep them.
     */
    public static function toUtf8(string $body, string $contentType = ''): string
    {
        $charset = '';
        if (preg_match('/charset=([\w-]+)/i', $contentType, $m)) {
            $charset = strtolower($m[1]);
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) {
            $charset = strtolower($m[1]);
        }
        $thai = in_array($charset, ['tis-620', 'tis620', 'windows-874', 'cp874', 'iso-8859-11'], true);
        if (!$thai && ($charset === 'utf-8' || mb_check_encoding($body, 'UTF-8'))) {
            return $body;
        }
        // Single-byte Thai → UTF-8. Thai letters 0xA1–0xFB map to U+0E01–U+0E5B;
        // done by hand so it works without iconv. ASCII passes through.
        $out = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($body[$i]);
            if ($b < 0x80) {
                $out .= $body[$i];
            } elseif ($b >= 0xA1 && $b <= 0xFB) {
                $out .= mb_chr(0x0E00 + $b - 0xA0, 'UTF-8');
            } elseif ($b === 0xA0) {
                $out .= "\u{00A0}";
            } else {
                $out .= mb_chr($b, 'UTF-8'); // windows-874 extras (rare): best effort
            }
        }
        return $out;
    }

    /** Fingerprint of a parsed chart, so apply() can tell the page changed. */
    public static function fingerprint(array $chart): string
    {
        return sha1(json_encode($chart, JSON_UNESCAPED_UNICODE));
    }

    private static function unitKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $name) ?? '');
    }

    /**
     * Work out what an import would do. $divisionMap maps a page ฝ่าย name to
     * an existing division id, or 0 = create it. Missing entries fall back to
     * a name match.
     *
     * @return array<string,mixed>
     */
    public static function plan(array $chart, array $divisionMap = [], bool $skipRetired = true): array
    {
        $pdo = Database::pdo();

        // Existing units by normalised name.
        $existing = [];
        foreach (['division', 'work', 'department'] as $type) {
            foreach (OrgUnit::all($type) as $u) {
                $existing[$type][self::unitKey($u['name'])] = (int) $u['id'];
            }
        }
        $divisionsList = OrgUnit::all('division');

        // Accounts by normalised name.
        $byName = [];
        foreach ($pdo->query('SELECT id, full_name, is_active FROM users')->fetchAll() as $u) {
            $byName[RmsOrgChartParser::normalizeName((string) $u['full_name'])][] = $u;
        }
        $matchUser = function (string $name) use ($byName): array {
            $cands = $byName[RmsOrgChartParser::normalizeName($name)] ?? [];
            if (count($cands) > 1) {
                $active = array_values(array_filter($cands, fn($c) => (int) $c['is_active'] === 1));
                if (count($active) === 1) {
                    $cands = $active;
                }
            }
            if (count($cands) === 1) {
                return ['status' => 'ok', 'id' => (int) $cands[0]['id'], 'full_name' => $cands[0]['full_name']];
            }
            return ['status' => $cands ? 'ambiguous' : 'missing'];
        };

        $plan = [
            'divisions' => [],      // [{name, target_id (0 = create), suggested_id}]
            'units' => [],          // [{type, name, division, exists, id}]
            'skipped_units' => [],  // names
            'people' => [],         // user id => {user_id, full_name, roles: [code => [unit refs]]}
            'unmatched' => [],      // [{name, where, title, reason}]
            'not_importable' => [], // [{name, where, title}] — staff attached straight to a ฝ่าย
        ];

        $addRole = function (int $userId, string $fullName, string $code, ?array $unitRef) use (&$plan): void {
            $plan['people'][$userId] ??= ['user_id' => $userId, 'full_name' => $fullName, 'roles' => []];
            $plan['people'][$userId]['roles'][$code] ??= [];
            if ($unitRef !== null && !in_array($unitRef, $plan['people'][$userId]['roles'][$code], true)) {
                $plan['people'][$userId]['roles'][$code][] = $unitRef;
            }
        };
        $person = function (array $p, string $where, string $code, ?array $unitRef) use ($matchUser, $addRole, &$plan): void {
            $m = $matchUser($p['name']);
            if ($m['status'] !== 'ok') {
                // One entry per person, listing every place they appear.
                $k = RmsOrgChartParser::normalizeName($p['name']);
                if (isset($plan['unmatched'][$k])) {
                    $plan['unmatched'][$k]['where'] .= ', ' . $where;
                } else {
                    $plan['unmatched'][$k] = ['name' => $p['name'], 'where' => $where, 'title' => $p['title'] ?? '', 'reason' => $m['status']];
                }
                return;
            }
            $addRole($m['id'], (string) $m['full_name'], $code, $unitRef);
        };

        if (!empty($chart['director'])) {
            $person($chart['director'], 'ผู้อำนวยการ', 'director', null);
        }

        foreach ($chart['divisions'] as $d) {
            $key = self::unitKey($d['name']);
            $suggested = $existing['division'][$key] ?? 0;
            $target = array_key_exists($d['name'], $divisionMap) ? (int) $divisionMap[$d['name']] : $suggested;
            $validIds = array_map(fn($x) => (int) $x['id'], $divisionsList);
            if ($target !== 0 && !in_array($target, $validIds, true)) {
                $target = $suggested;
            }
            $plan['divisions'][] = ['name' => $d['name'], 'target_id' => $target, 'suggested_id' => $suggested];
            $divRef = ['type' => 'division', 'name' => $d['name'], 'id' => $target ?: null];

            foreach ($d['lead'] as $p) {
                $person($p, $d['name'], 'deputy', $divRef);
            }
            foreach ($d['staff'] as $p) {
                $plan['not_importable'][] = ['name' => $p['name'], 'where' => $d['name'], 'title' => $p['title']];
            }

            foreach ($d['units'] as $u) {
                if ($skipRetired && preg_match(self::RETIRED_PATTERN, $u['name'])) {
                    $plan['skipped_units'][] = $u['name'];
                    continue;
                }
                $type = $u['kind'] === 'department' ? 'department' : 'work';
                $id = $existing[$type][self::unitKey($u['name'])] ?? null;
                $plan['units'][] = ['type' => $type, 'name' => $u['name'], 'division' => $d['name'], 'exists' => $id !== null, 'id' => $id];
                $ref = ['type' => $type, 'name' => $u['name'], 'id' => $id];

                foreach ($u['members'] as $m) {
                    if ($type === 'department') {
                        $code = $m['head'] ? 'dept' : 'teacher';
                    } else {
                        $code = $m['head'] ? 'supervisor' : 'staff';
                    }
                    $person($m, $u['name'], $code, $ref);
                }
            }
        }

        $plan['unmatched'] = array_values($plan['unmatched']);
        $plan['people'] = array_values($plan['people']);
        usort($plan['people'], fn($a, $b) => strcmp($a['full_name'], $b['full_name']));
        return $plan;
    }

    /**
     * Write a plan: create missing units, then merge each person's roles.
     *
     * @return array{units_created:int,people_updated:int,roles_added:int}
     */
    public static function apply(array $plan): array
    {
        $pdo = Database::pdo();
        $created = 0;

        // 1. ฝ่าย: create the ones mapped to "new".
        $divIds = [];
        foreach ($plan['divisions'] as $d) {
            if ((int) $d['target_id'] === 0) {
                $existingId = self::findUnitId('division', $d['name']);
                if ($existingId === null) {
                    OrgUnit::create('division', $d['name']);
                    $created++;
                    $existingId = self::findUnitId('division', $d['name']);
                }
                $divIds[$d['name']] = $existingId;
            } else {
                $divIds[$d['name']] = (int) $d['target_id'];
            }
        }

        // 2. งาน / แผนก.
        $unitIds = [];
        foreach ($plan['units'] as $u) {
            $id = self::findUnitId($u['type'], $u['name']);
            $parent = $divIds[$u['division']] ?? null;
            if ($id === null) {
                OrgUnit::create($u['type'], $u['name'], OrgUnit::hasDivision($u['type']) ? $parent : null);
                $created++;
                $id = self::findUnitId($u['type'], $u['name']);
            } else {
                OrgUnit::fillDivision($u['type'], $id, $parent);
            }
            $unitIds[$u['type'] . '|' . self::unitKey($u['name'])] = $id;
        }
        $resolve = function (array $ref) use ($divIds, $unitIds): ?int {
            if ($ref['type'] === 'division') {
                return $divIds[$ref['name']] ?? null;
            }
            return $unitIds[$ref['type'] . '|' . self::unitKey($ref['name'])] ?? null;
        };

        // 3. People: existing roles ∪ imported roles.
        $updated = 0;
        $added = 0;
        foreach ($plan['people'] as $p) {
            $uid = (int) $p['user_id'];
            $codes = User::rolesFor($uid);
            $units = User::roleUnitsFor($uid);
            $before = self::canon($codes, $units);

            foreach ($p['roles'] as $code => $refs) {
                if (!in_array($code, $codes, true)) {
                    $codes[] = $code;
                    $added++;
                }
                $ids = [];
                foreach ($refs as $ref) {
                    $id = $resolve($ref);
                    if ($id !== null) {
                        $ids[] = $id;
                    }
                }
                if (Role::needsSingleUnit($code)) {
                    // One ฝ่าย per deputy: the chart wins.
                    if ($ids) {
                        $units[$code] = [$ids[0]];
                    }
                } else {
                    $units[$code] = array_values(array_unique(array_merge($units[$code] ?? [], $ids)));
                }
            }

            // Drop the bare default `staff` (no unit) once a real role arrived.
            if (!isset($p['roles']['staff']) && in_array('staff', $codes, true) && empty($units['staff'])) {
                $codes = array_values(array_diff($codes, ['staff']));
            }

            if (self::canon($codes, $units) !== $before) {
                User::setRoles($uid, $codes, $units);
                $updated++;
            }
        }

        Setting::set(self::LAST_RUN_KEY, date('Y-m-d H:i:s'));
        return ['units_created' => $created, 'people_updated' => $updated, 'roles_added' => $added];
    }

    /** Order-insensitive form of a role set, to tell whether anything changed. */
    private static function canon(array $codes, array $units): string
    {
        sort($codes);
        $u = [];
        foreach ($codes as $c) {
            $ids = array_map('intval', $units[$c] ?? []);
            sort($ids);
            $u[$c] = array_values(array_unique($ids));
        }
        return json_encode([$codes, $u]);
    }

    private static function findUnitId(string $type, string $name): ?int
    {
        $key = self::unitKey($name);
        foreach (OrgUnit::all($type) as $u) {
            if (self::unitKey($u['name']) === $key) {
                return (int) $u['id'];
            }
        }
        return null;
    }
}

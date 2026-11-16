<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Role;
use App\Models\Setting;
use RuntimeException;

/**
 * Builds the ฝ่าย / งาน / แผนก structure and people's roles from the RMS
 * org chart page ({external_api_base_url}/pms_graph.php — public, no login).
 *
 * Two steps so an admin can check before anything is written:
 *   plan()  — fetch + parse + diff against the database, writes nothing
 *   apply() — re-plans and executes it
 *
 * Applying is additive: it creates/renames units and adds role+unit links,
 * but never deletes a unit or removes a role an admin gave someone. The one
 * exception is the bare default `staff` role (no unit) that people-sync gives
 * every new account — it is dropped once the chart gives that person a real
 * role, otherwise everyone would keep a meaningless extra role.
 *
 * People are matched to accounts by full name (the chart has no national ID).
 */
final class OrgChartSync
{
    private const PAGE_PATH = '/pms_graph.php';

    /**
     * RMS has renamed some ฝ่าย since the app's seed data was written. When
     * the RMS name doesn't exist yet but the old one does, the old row is
     * renamed (keeping its id and every assignment) instead of duplicated.
     */
    private const DIVISION_ALIASES = [
        'ฝ่ายยุทธศาสตร์และแผนงาน' => ['ฝ่ายแผนงานและความร่วมมือ'],
        'ฝ่ายกิจการนักเรียน นักศึกษา' => ['ฝ่ายพัฒนากิจการนักเรียนนักศึกษา', 'ฝ่ายกิจการนักเรียนนักศึกษา'],
    ];

    /** Position label (RMS) → role code, per unit type. Anything else falls to the default. */
    private const WORK_POSITIONS = [
        'หัวหน้างาน' => 'supervisor',
        'ผู้ช่วยหัวหน้างาน' => 'asst_supervisor',
        'ผู้ช่วยงาน' => 'work_teacher',
    ];
    private const WORK_DEFAULT = 'staff';
    private const DEPT_POSITIONS = [
        'หัวหน้าแผนก' => 'dept',
        'หัวหน้าภาควิชา' => 'dept',
    ];
    private const DEPT_DEFAULT = 'teacher';

    public static function pageUrl(): string
    {
        $base = rtrim((string) Setting::get(PeopleSync::SETTING_KEY, ''), '/');
        if ($base === '') {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า URL ของระบบภายนอก (RMS) กรุณากำหนดที่หน้าตั้งค่าระบบก่อน');
        }
        return $base . self::PAGE_PATH;
    }

    // ------------------------------------------------------------------
    // Parsing
    // ------------------------------------------------------------------

    /**
     * @return array{
     *   director: ?string,
     *   divisions: list<array{name:string, deputies:list<string>, units:list<array{type:string,name:string,members:list<array{name:string,position:string}>}>}>,
     *   ignored: list<string>
     * }
     */
    public static function parse(string $html): array
    {
        if (preg_match('/charset=["\']?(windows-874|tis-620)/i', substr($html, 0, 4000))) {
            $converted = @iconv('CP874', 'UTF-8//IGNORE', $html);
            if ($converted !== false) {
                $html = preg_replace('/charset=["\']?(windows-874|tis-620)/i', 'charset=utf-8', $converted);
            }
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $x = new \DOMXPath($dom);
        $cls = static fn(string $c): string => "contains(concat(' ',normalize-space(@class),' '),' {$c} ')";
        $text = static fn(?\DOMNode $n): string => $n ? self::norm($n->textContent) : '';

        $out = ['director' => null, 'divisions' => [], 'ignored' => []];

        $root = $x->query("//*[{$cls('pg-root')}]//*[{$cls('pg-root-name')}]")->item(0);
        $out['director'] = $root ? ($text($root) ?: null) : null;

        $divByNode = [];
        foreach ($x->query("//*[{$cls('pg-div')}]") as $d) {
            /** @var \DOMElement $d */
            $headEl = $x->query(".//*[{$cls('pg-div-head')}]", $d)->item(0);
            $name = '';
            if ($headEl) {
                // The head holds the name plus the head-count ("93 คน"); take
                // the first text that starts with ฝ่าย.
                foreach ($x->query('.//text()', $headEl) as $t) {
                    $s = self::norm($t->textContent);
                    if (str_starts_with($s, 'ฝ่าย')) {
                        $name = $s;
                        break;
                    }
                }
            }
            if ($name === '') {
                continue;
            }
            $deputies = [];
            foreach ($x->query(".//*[{$cls('pg-div-lead')}]//*[{$cls('pg-mem-t')}]", $d) as $m) {
                $b = $text($x->query('./b', $m)->item(0));
                $pos = $text($x->query('./small', $m)->item(0));
                if ($b !== '' && str_contains($pos, 'รองผู้อำนวยการ')) {
                    $deputies[] = $b;
                }
            }
            $divByNode[$d->getAttribute('data-node')] = count($out['divisions']);
            $out['divisions'][] = ['name' => $name, 'deputies' => $deputies, 'units' => []];
        }

        // Units point at their ฝ่าย through data-parent.
        $rawUnits = [];
        foreach ($x->query("//*[{$cls('pg-unit')}]") as $u) {
            /** @var \DOMElement $u */
            $parent = $u->getAttribute('data-parent');
            $name = $text($x->query(".//*[{$cls('pg-unit-name')}]", $u)->item(0));
            if ($name === '' || !isset($divByNode[$parent])) {
                continue;
            }
            $members = [];
            foreach ($x->query(".//*[{$cls('pg-unit-list')}]//*[{$cls('pg-mem-t')}]", $u) as $m) {
                $b = $text($x->query('./b', $m)->item(0));
                if ($b !== '') {
                    $members[] = ['name' => $b, 'position' => $text($x->query('./small', $m)->item(0))];
                }
            }
            $rawUnits[] = ['div' => $divByNode[$parent], 'name' => $name, 'members' => $members];
        }

        // Clean names: drop units marked for deletion, fold sub-groups such
        // as "งานอาคารสถานที่ (คนงานเขต 1)" into their main unit when that
        // main unit exists in the same ฝ่าย.
        // ...or when two or more sub-groups share the same main name
        // ("งานวิทยบริการและห้องสมุด (ห้องสมุด)" + "(ห้องบริการอินเทอร์เน็ต)").
        $baseNames = [];
        $subCount = [];
        foreach ($rawUnits as $r) {
            $baseNames[$r['div'] . '|' . $r['name']] = true;
            if (!str_contains($r['name'], 'สำหรับลบ') && preg_match('/^(.*\S)\s*\([^()]*\)$/u', $r['name'], $mm)) {
                $k = $r['div'] . '|' . $mm[1];
                $subCount[$k] = ($subCount[$k] ?? 0) + 1;
            }
        }
        foreach ($subCount as $k => $n) {
            if ($n >= 2) {
                $baseNames[$k] = true;
            }
        }
        $merged = [];
        foreach ($rawUnits as $r) {
            if (str_contains($r['name'], 'สำหรับลบ')) {
                $out['ignored'][] = $r['name'] . ' (ทำเครื่องหมายสำหรับลบใน RMS)';
                continue;
            }
            $name = $r['name'];
            if (preg_match('/^(.*\S)\s*\([^()]*\)$/u', $name, $mm) && isset($baseNames[$r['div'] . '|' . $mm[1]])) {
                $name = $mm[1];
            }
            if (str_starts_with($name, 'แผนกวิชา') || str_starts_with($name, 'ภาควิชา')) {
                $type = 'department';
            } elseif (str_starts_with($name, 'งาน')) {
                $type = 'work';
            } else {
                $out['ignored'][] = $name . ' (ไม่ทราบประเภทหน่วยงาน)';
                continue;
            }
            $key = $r['div'] . '|' . $type . '|' . $name;
            if (!isset($merged[$key])) {
                $merged[$key] = ['div' => $r['div'], 'type' => $type, 'name' => $name, 'members' => []];
            }
            array_push($merged[$key]['members'], ...$r['members']);
        }
        foreach ($merged as $m) {
            $out['divisions'][$m['div']]['units'][] = ['type' => $m['type'], 'name' => $m['name'], 'members' => $m['members']];
        }

        return $out;
    }

    public static function roleFor(string $unitType, string $position): string
    {
        if ($unitType === 'work') {
            return self::WORK_POSITIONS[$position] ?? self::WORK_DEFAULT;
        }
        return self::DEPT_POSITIONS[$position] ?? self::DEPT_DEFAULT;
    }

    private static function norm(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    // ------------------------------------------------------------------
    // Planning (read-only)
    // ------------------------------------------------------------------

    /**
     * @return array{
     *   units: list<array{type:string,name:string,action:string,from?:string,division?:string}>,
     *   people: list<array{name:string,user_id:int,username:string,add:list<array{role:string,type:?string,unit:?string}>,drop_default_staff:bool}>,
     *   unmatched: list<array{name:string,reason:string,wants:list<string>}>,
     *   ignored: list<string>,
     *   chart: array
     * }
     */
    public static function plan(?string $html = null): array
    {
        $html ??= self::fetch(self::pageUrl());
        $chart = self::parse($html);
        if (!$chart['divisions']) {
            throw new RuntimeException('อ่านผังองค์กรจาก RMS ไม่ได้ — โครงสร้างหน้าเว็บอาจเปลี่ยนไป');
        }
        $pdo = Database::pdo();

        // ---- units --------------------------------------------------
        $existing = [];
        foreach (['division' => 'divisions', 'work' => 'works', 'department' => 'departments'] as $type => $table) {
            foreach ($pdo->query("SELECT * FROM {$table}")->fetchAll() as $row) {
                $existing[$type][self::norm($row['name'])] = $row;
            }
        }
        $divById = [];
        foreach ($existing['division'] ?? [] as $row) {
            $divById[(int) $row['id']] = $row['name'];
        }

        $unitPlan = [];
        foreach ($chart['divisions'] as $d) {
            if (isset($existing['division'][$d['name']])) {
                $unitPlan[] = ['type' => 'division', 'name' => $d['name'], 'action' => 'keep'];
            } else {
                $from = null;
                foreach (self::DIVISION_ALIASES[$d['name']] ?? [] as $alias) {
                    if (isset($existing['division'][$alias])) {
                        $from = $alias;
                        break;
                    }
                }
                $unitPlan[] = $from !== null
                    ? ['type' => 'division', 'name' => $d['name'], 'action' => 'rename', 'from' => $from]
                    : ['type' => 'division', 'name' => $d['name'], 'action' => 'create'];
            }
            foreach ($d['units'] as $u) {
                $row = $existing[$u['type']][$u['name']] ?? null;
                if ($row === null) {
                    $unitPlan[] = ['type' => $u['type'], 'name' => $u['name'], 'action' => 'create', 'division' => $d['name']];
                } elseif ($u['type'] === 'work' && ($divById[(int) ($row['division_id'] ?? 0)] ?? null) !== $d['name']
                    && !in_array($divById[(int) ($row['division_id'] ?? 0)] ?? null, self::DIVISION_ALIASES[$d['name']] ?? [], true)) {
                    $unitPlan[] = ['type' => 'work', 'name' => $u['name'], 'action' => 'move', 'division' => $d['name']];
                } else {
                    $unitPlan[] = ['type' => $u['type'], 'name' => $u['name'], 'action' => 'keep'];
                }
            }
        }

        // ---- people: what the chart says each name should hold -------------
        /** @var array<string, array<string, array{type:?string, units:array<string,true>}>> $wants name => role => units */
        $wants = [];
        $want = static function (string $name, string $role, ?string $type, ?string $unit) use (&$wants): void {
            $wants[$name][$role] ??= ['type' => $type, 'units' => []];
            if ($unit !== null) {
                $wants[$name][$role]['units'][$unit] = true;
            }
        };
        if ($chart['director']) {
            $want($chart['director'], 'director', null, null);
        }
        foreach ($chart['divisions'] as $d) {
            foreach ($d['deputies'] as $dep) {
                $want($dep, 'deputy', 'division', $d['name']);
            }
            foreach ($d['units'] as $u) {
                foreach ($u['members'] as $m) {
                    $want($m['name'], self::roleFor($u['type'], $m['position']), $u['type'], $u['name']);
                }
            }
        }

        // ---- match names to accounts ------------------------------------
        $byName = [];
        foreach ($pdo->query('SELECT id, username, full_name, is_active FROM users')->fetchAll() as $u) {
            $byName[self::norm((string) $u['full_name'])][] = $u;
        }

        $currentRoles = [];
        foreach ($pdo->query('SELECT ur.user_id, r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id')->fetchAll() as $r) {
            $currentRoles[(int) $r['user_id']][$r['code']] = true;
        }
        $currentUnits = []; // user => role => "type|name" => true
        foreach ($pdo->query(
            'SELECT uru.user_id, r.code, d.name AS dname, w.name AS wname, p.name AS pname
             FROM user_role_units uru JOIN roles r ON r.id = uru.role_id
             LEFT JOIN divisions d ON d.id = uru.division_id
             LEFT JOIN works w ON w.id = uru.work_id
             LEFT JOIN departments p ON p.id = uru.department_id'
        )->fetchAll() as $r) {
            $key = $r['dname'] !== null ? 'division|' . self::norm($r['dname'])
                : ($r['wname'] !== null ? 'work|' . self::norm($r['wname']) : 'department|' . self::norm((string) $r['pname']));
            $currentUnits[(int) $r['user_id']][$r['code']][$key] = true;
        }
        // A renamed ฝ่าย keeps its links — count links to the old name as the new one.
        foreach ($unitPlan as $p) {
            if ($p['action'] === 'rename') {
                foreach ($currentUnits as $uid => $roles) {
                    foreach ($roles as $code => $keys) {
                        if (isset($keys['division|' . $p['from']])) {
                            $currentUnits[$uid][$code]['division|' . $p['name']] = true;
                        }
                    }
                }
            }
        }

        $people = [];
        $unmatched = [];
        foreach ($wants as $name => $roles) {
            $wantList = [];
            foreach ($roles as $code => $info) {
                $wantList[] = (Role::find($code)['short_label'] ?? $code) . ($info['units'] ? ' · ' . implode(', ', array_keys($info['units'])) : '');
            }
            $cands = $byName[$name] ?? [];
            if (count($cands) > 1) {
                // Same name on several accounts (e.g. a manual account plus the
                // RMS-synced one) — prefer active accounts keyed by national ID.
                $narrowed = array_values(array_filter($cands, static fn($u) => (int) $u['is_active'] === 1 && preg_match('/^\d{13}$/', (string) $u['username'])));
                if (count($narrowed) === 1) {
                    $cands = $narrowed;
                }
            }
            if (count($cands) !== 1) {
                $unmatched[] = [
                    'name' => $name,
                    'reason' => $cands ? 'พบบัญชีชื่อนี้ ' . count($cands) . ' บัญชี' : 'ไม่พบบัญชีผู้ใช้ชื่อนี้',
                    'wants' => $wantList,
                ];
                continue;
            }
            $user = $cands[0];
            $uid = (int) $user['id'];
            $add = [];
            foreach ($roles as $code => $info) {
                if ($info['units'] === []) {
                    if (!isset($currentRoles[$uid][$code])) {
                        $add[] = ['role' => $code, 'type' => null, 'unit' => null];
                    }
                    continue;
                }
                foreach (array_keys($info['units']) as $unitName) {
                    if (!isset($currentUnits[$uid][$code][$info['type'] . '|' . $unitName])) {
                        $add[] = ['role' => $code, 'type' => $info['type'], 'unit' => $unitName];
                    }
                }
            }
            $dropDefault = isset($currentRoles[$uid]['staff']) && empty($currentUnits[$uid]['staff']) && !isset($roles['staff']);
            if ($add || $dropDefault) {
                $people[] = [
                    'name' => $name,
                    'user_id' => $uid,
                    'username' => (string) $user['username'],
                    'add' => $add,
                    'drop_default_staff' => $dropDefault,
                ];
            }
        }

        return [
            'units' => $unitPlan,
            'people' => $people,
            'unmatched' => $unmatched,
            'ignored' => $chart['ignored'],
            'chart' => $chart,
        ];
    }

    // ------------------------------------------------------------------
    // Applying
    // ------------------------------------------------------------------

    /** @return array{units_created:int,units_renamed:int,units_moved:int,people_updated:int,links_added:int,unmatched:int} */
    public static function apply(): array
    {
        @set_time_limit(0);
        $plan = self::plan();
        $pdo = Database::pdo();
        $roles = Role::all();
        $sum = ['units_created' => 0, 'units_renamed' => 0, 'units_moved' => 0, 'people_updated' => 0, 'links_added' => 0, 'unmatched' => count($plan['unmatched'])];

        $pdo->beginTransaction();
        try {
            // Divisions first — works hang off them.
            foreach ($plan['units'] as $u) {
                if ($u['type'] !== 'division') {
                    continue;
                }
                if ($u['action'] === 'create') {
                    $pdo->prepare('INSERT INTO divisions (name) VALUES (?)')->execute([$u['name']]);
                    $sum['units_created']++;
                } elseif ($u['action'] === 'rename') {
                    $pdo->prepare('UPDATE divisions SET name = ? WHERE name = ?')->execute([$u['name'], $u['from']]);
                    $sum['units_renamed']++;
                }
            }
            $ids = self::unitIds($pdo);
            foreach ($plan['units'] as $u) {
                if ($u['type'] === 'division' || $u['action'] === 'keep') {
                    continue;
                }
                $divId = isset($u['division']) ? ($ids['division'][$u['division']] ?? null) : null;
                if ($u['action'] === 'create' && $u['type'] === 'work') {
                    $pdo->prepare('INSERT INTO works (name, division_id) VALUES (?, ?)')->execute([$u['name'], $divId]);
                    $sum['units_created']++;
                } elseif ($u['action'] === 'create') {
                    $pdo->prepare('INSERT INTO departments (name) VALUES (?)')->execute([$u['name']]);
                    $sum['units_created']++;
                } elseif ($u['action'] === 'move') {
                    $pdo->prepare('UPDATE works SET division_id = ? WHERE name = ?')->execute([$divId, $u['name']]);
                    $sum['units_moved']++;
                }
            }
            $ids = self::unitIds($pdo);

            $insRole = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
            $staffId = (int) ($roles['staff']['id'] ?? 0);
            foreach ($plan['people'] as $p) {
                $uid = $p['user_id'];
                foreach ($p['add'] as $a) {
                    $roleId = (int) ($roles[$a['role']]['id'] ?? 0);
                    if ($roleId === 0) {
                        continue;
                    }
                    $insRole->execute([$uid, $roleId]);
                    if ($a['type'] === null) {
                        $sum['links_added']++;
                        continue;
                    }
                    $unitId = $ids[$a['type']][$a['unit']] ?? null;
                    if ($unitId === null) {
                        continue;
                    }
                    $col = ['division' => 'division_id', 'work' => 'work_id', 'department' => 'department_id'][$a['type']];
                    // NULL columns defeat the UNIQUE key, so check before inserting.
                    $chk = $pdo->prepare("SELECT 1 FROM user_role_units WHERE user_id = ? AND role_id = ? AND {$col} = ? LIMIT 1");
                    $chk->execute([$uid, $roleId, $unitId]);
                    if ($chk->fetchColumn() === false) {
                        $pdo->prepare("INSERT INTO user_role_units (user_id, role_id, {$col}) VALUES (?, ?, ?)")->execute([$uid, $roleId, $unitId]);
                        $sum['links_added']++;
                    }
                }
                if ($p['drop_default_staff'] && $staffId > 0) {
                    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?')->execute([$uid, $staffId]);
                }
                $sum['people_updated']++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Setting::set('org_sync_last_run_at', date('c'));
        Setting::set('org_sync_last_summary', json_encode($sum, JSON_UNESCAPED_UNICODE));
        return $sum;
    }

    /** @return array<string, array<string,int>> type => normalised name => id */
    private static function unitIds(\PDO $pdo): array
    {
        $out = [];
        foreach (['division' => 'divisions', 'work' => 'works', 'department' => 'departments'] as $type => $table) {
            foreach ($pdo->query("SELECT id, name FROM {$table}")->fetchAll() as $r) {
                $out[$type][self::norm($r['name'])] = (int) $r['id'];
            }
        }
        return $out;
    }

    private static function fetch(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('เซิร์ฟเวอร์นี้ไม่มี PHP extension "curl" ซึ่งจำเป็นสำหรับการเชื่อมต่อระบบภายนอก');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new RuntimeException('เชื่อมต่อ RMS ไม่สำเร็จ: ' . $error);
        }
        if ($status < 200 || $status >= 300 || !is_string($body) || $body === '') {
            throw new RuntimeException("RMS ตอบกลับด้วยสถานะ HTTP {$status}");
        }
        return $body;
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Turns RMS's public staff-structure page (pms_graph.php) into a plain
 * array: the director, each ฝ่าย with its deputy and any staff attached
 * directly to it, and each งาน / แผนก under it with its head(s) and members.
 *
 * The page is a rendered UI, not an API, so it may be restyled. To survive
 * small changes the parser:
 *   - keys off the tree's data-node / data-parent attributes (structure)
 *     rather than visual layout or nesting depth,
 *   - matches CSS classes by token (any element with class "x"), never by
 *     the full class attribute,
 *   - has fallbacks for each field (e.g. head = class "is-head" OR a role
 *     label containing "หัวหน้า"),
 *   - keeps every selector in SEL below, so a rename is a one-line fix,
 *   - refuses to return a result that looks wrong (no ฝ่าย, no people)
 *     instead of handing a half-parsed chart to the importer.
 */
final class RmsOrgChartParser
{
    /** Class tokens the parser looks for. Adjust here if RMS renames them. */
    private const SEL = [
        'root'          => 'pg-root',        // director block (data-node="root")
        'root_name'     => 'pg-root-name',
        'root_title'    => 'pg-root-over',
        'division'      => 'pg-div',         // ฝ่าย block
        'division_lead' => 'is-lead',        // deputy within a ฝ่าย
        'unit'          => 'pg-unit',        // งาน / แผนก block
        'unit_name'     => 'pg-unit-name',
        'unit_group'    => 'pg-kids-label',  // "แผนกวิชา" / "งาน" heading before units
        'member'        => 'pg-mem',         // a person button
        'member_head'   => 'is-head',
        'member_text'   => 'pg-mem-t',       // <b>name</b><small>role</small>
    ];

    /** Thai honorifics stripped before comparing names. Longest first. */
    public const NAME_PREFIXES = [
        'ว่าที่ร้อยตรีหญิง', 'ว่าที่ร้อยตรี', 'ว่าที่ ร.ต.หญิง', 'ว่าที่ ร.ต.', 'ว่าที่ร.ต.', 'ว่าที่',
        'นางสาว', 'น.ส.', 'นาง', 'นาย', 'ดร.', 'ผศ.', 'รศ.', 'ศ.',
        'mrs.', 'mrs', 'miss', 'ms.', 'ms', 'mr.', 'mr', 'dr.',
    ];

    /**
     * @return array{
     *   director: ?array{name:string,title:string},
     *   divisions: array<int,array{
     *     name:string,
     *     lead: array<int,array{name:string,title:string}>,
     *     staff: array<int,array{name:string,title:string}>,
     *     units: array<int,array{name:string,kind:string,members:array<int,array{name:string,title:string,head:bool}>}>
     *   }>
     * }
     */
    public static function parse(string $html): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // The caller has already converted the bytes to UTF-8, but the page
        // still declares <meta charset=windows-874>, which makes libxml
        // re-decode and abort. Turning every non-ASCII char into a numeric
        // entity makes the declared charset irrelevant.
        $ascii = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $doc->loadHTML($ascii, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        // ---- director ----
        $director = null;
        $root = self::first($xp, '//*[@data-node="root"]') ?? self::first($xp, self::cls(self::SEL['root']));
        if ($root) {
            $name = self::text(self::first($xp, './/' . self::clsRel(self::SEL['root_name']), $root))
                ?: self::memberName($xp, $root);
            if ($name !== '') {
                $director = [
                    'name' => $name,
                    'title' => self::text(self::first($xp, './/' . self::clsRel(self::SEL['root_title']), $root)) ?: 'ผู้อำนวยการ',
                ];
            }
        }

        // ---- ฝ่าย: every node whose parent is the root ----
        $divisions = [];
        $divNodes = [];
        foreach ($xp->query('//*[@data-node][@data-parent="root"]') as $el) {
            /** @var DOMElement $el */
            $divNodes[$el->getAttribute('data-node')] = $el;
        }
        if (!$divNodes) { // fallback: by class
            foreach ($xp->query(self::cls(self::SEL['division'])) as $i => $el) {
                $divNodes['div' . $i] = $el;
            }
        }

        foreach ($divNodes as $nodeId => $divEl) {
            $name = self::text(self::first($xp, './/h1|.//h2|.//h3|.//h4', $divEl));
            if ($name === '') {
                continue;
            }

            // Members of the ฝ่าย itself = person elements inside the ฝ่าย block
            // but not inside any of its units.
            $lead = [];
            $staff = [];
            foreach ($xp->query('.//' . self::clsRel(self::SEL['member']), $divEl) as $m) {
                /** @var DOMElement $m */
                if (self::insideClass($m, self::SEL['unit'], $divEl)) {
                    continue;
                }
                $p = self::member($xp, $m);
                if ($p === null) {
                    continue;
                }
                $isLead = self::hasClass($m, self::SEL['division_lead'])
                    || mb_strpos($p['title'], 'รองผู้อำนวยการ') !== false;
                if ($isLead) {
                    $lead[] = ['name' => $p['name'], 'title' => $p['title']];
                } else {
                    $staff[] = ['name' => $p['name'], 'title' => $p['title']];
                }
            }

            // Units: nodes whose data-parent is this ฝ่าย (anywhere in the page),
            // falling back to unit-class elements nested inside the ฝ่าย block.
            $unitEls = [];
            if (!str_starts_with((string) $nodeId, 'div')) {
                foreach ($xp->query('//*[@data-parent="' . $nodeId . '"]') as $u) {
                    $unitEls[] = $u;
                }
            }
            if (!$unitEls) {
                // No tree attributes: look inside the ฝ่าย block, then in its
                // wrapper (units are often a sibling of the ฝ่าย card) as long
                // as that wrapper holds only this one ฝ่าย.
                foreach ([$divEl, $divEl->parentNode] as $scope) {
                    if (!$scope instanceof DOMElement) {
                        continue;
                    }
                    if ($scope !== $divEl && $xp->query('.//' . self::clsRel(self::SEL['division']), $scope)->length > 1) {
                        break;
                    }
                    foreach ($xp->query('.//' . self::clsRel(self::SEL['unit']), $scope) as $u) {
                        $unitEls[] = $u;
                    }
                    if ($unitEls) {
                        break;
                    }
                }
            }

            $units = [];
            foreach ($unitEls as $u) {
                /** @var DOMElement $u */
                $uname = self::text(self::first($xp, './/' . self::clsRel(self::SEL['unit_name']), $u))
                    ?: self::text(self::first($xp, './/h1|.//h2|.//h3|.//h4|.//h5', $u));
                if ($uname === '') {
                    continue;
                }
                $members = [];
                $seen = [];
                foreach ($xp->query('.//' . self::clsRel(self::SEL['member']), $u) as $m) {
                    $p = self::member($xp, $m);
                    if ($p === null || isset($seen[$p['name']])) {
                        continue;
                    }
                    $seen[$p['name']] = true;
                    $p['head'] = self::hasClass($m, self::SEL['member_head'])
                        || (mb_strpos($p['title'], 'หัวหน้า') === 0); // "หัวหน้างาน", "หัวหน้าแผนก" — not "ผู้ช่วยหัวหน้า…"
                    $members[] = $p;
                }
                $units[] = [
                    'name' => $uname,
                    'kind' => self::unitKind($xp, $u, $uname),
                    'members' => $members,
                ];
            }

            $divisions[] = ['name' => $name, 'lead' => $lead, 'staff' => $staff, 'units' => $units];
        }

        // ---- sanity checks: refuse a chart that clearly didn't parse ----
        $people = 0;
        foreach ($divisions as $d) {
            $people += count($d['lead']) + count($d['staff']);
            foreach ($d['units'] as $u) {
                $people += count($u['members']);
            }
        }
        if (!$divisions || $people === 0) {
            throw new RuntimeException('อ่านโครงสร้างจากหน้าเว็บไม่ได้ — รูปแบบหน้าเว็บอาจเปลี่ยนไป (ไม่พบฝ่ายหรือรายชื่อบุคลากร)');
        }

        return ['director' => $director, 'divisions' => $divisions];
    }

    /** "department" for แผนก/ภาควิชา, otherwise "work" (งาน). */
    private static function unitKind(DOMXPath $xp, DOMElement $unit, string $name): string
    {
        if (preg_match('/^(แผนก|ภาควิชา|สาขา)/u', $name)) {
            return 'department';
        }
        if (preg_match('/^งาน/u', $name)) {
            return 'work';
        }
        // Otherwise use the nearest group heading before the unit ("แผนกวิชา" / "งาน").
        $label = $xp->query('preceding-sibling::*[' . self::clsPred(self::SEL['unit_group']) . '][1]', $unit)->item(0);
        $text = self::text($label);
        return preg_match('/แผนก|ภาควิชา/u', $text) ? 'department' : 'work';
    }

    /** @return array{name:string,title:string}|null */
    private static function member(DOMXPath $xp, DOMElement $m): ?array
    {
        $box = self::first($xp, './/' . self::clsRel(self::SEL['member_text']), $m) ?? $m;
        $name = self::text(self::first($xp, './/b|.//strong', $box));
        $title = self::text(self::first($xp, './/small', $box));
        if ($name === '') {
            return null;
        }
        return ['name' => $name, 'title' => $title];
    }

    private static function memberName(DOMXPath $xp, DOMElement $ctx): string
    {
        $m = self::first($xp, './/' . self::clsRel(self::SEL['member']), $ctx);
        return $m ? (self::member($xp, $m)['name'] ?? '') : '';
    }

    /** Lower-cased, honorific-free, single-spaced name for matching. */
    public static function normalizeName(string $name): string
    {
        $n = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $lower = mb_strtolower($n);
        foreach (self::NAME_PREFIXES as $p) {
            if (str_starts_with($lower, $p)) {
                $lower = ltrim(mb_substr($lower, mb_strlen($p)));
                break;
            }
        }
        return $lower;
    }

    // ---- small DOM helpers -------------------------------------------------

    private static function clsPred(string $token): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")';
    }

    private static function cls(string $token): string
    {
        return '//*[' . self::clsPred($token) . ']';
    }

    private static function clsRel(string $token): string
    {
        return '*[' . self::clsPred($token) . ']';
    }

    private static function first(DOMXPath $xp, string $q, ?\DOMNode $ctx = null): ?DOMElement
    {
        $r = $ctx ? $xp->query($q, $ctx) : $xp->query($q);
        $n = $r ? $r->item(0) : null;
        return $n instanceof DOMElement ? $n : null;
    }

    private static function text(?\DOMNode $n): string
    {
        if (!$n) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '');
    }

    private static function hasClass(DOMElement $el, string $token): bool
    {
        return in_array($token, preg_split('/\s+/', $el->getAttribute('class')) ?: [], true);
    }

    private static function insideClass(DOMElement $el, string $token, DOMElement $stop): bool
    {
        for ($p = $el->parentNode; $p && $p !== $stop; $p = $p->parentNode) {
            if ($p instanceof DOMElement && self::hasClass($p, $token)) {
                return true;
            }
        }
        return false;
    }
}

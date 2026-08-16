<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Ticket
{
    public const STATUS = [
        'new'       => ['ใหม่ / รอรับทราบ',      'rgba(37,99,235,.15)',  '#1d4ed8'],
        'ack'       => ['รับทราบแล้ว',            'rgba(14,165,233,.15)', '#0369a1'],
        'doing'     => ['กำลังดำเนินการ',          'rgba(217,119,6,.15)',  '#b45309'],
        'review'    => ['ขอทบทวนคำสั่ง',          'rgba(168,85,247,.15)', '#7e22ce'],
        'submitted' => ['ส่งงานแล้ว รออนุมัติ',    'rgba(99,102,241,.15)', '#4338ca'],
        'done'      => ['เสร็จสิ้น / อนุมัติ',      'rgba(16,185,129,.15)', '#047857'],
        'forced'    => ['ปิดงานโดยคำสั่ง',         'rgba(100,116,139,.18)','#475569'],
    ];

    public const PRIORITY = [
        'urgent' => ['ด่วนที่สุด', 'rgba(220,38,38,.15)', '#b91c1c'],
        'high'   => ['ด่วน',       'rgba(234,88,12,.15)', '#c2410c'],
        'normal' => ['ปกติ',       'rgba(100,116,139,.16)', '#475569'],
    ];

    public static function generateCode(): string
    {
        $year = (int) date('Y') + 543; // Buddhist Era, matches the design (พ.ศ.)
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE code LIKE ?");
        $st->execute(["TK-{$year}-%"]);
        $n = (int) $st->fetchColumn() + 1;
        return sprintf('TK-%d-%03d', $year, $n);
    }

    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $code = self::generateCode();
        $isCross = Role::isCrossLevel($data['from_role'], $data['to_role']) ? 1 : 0;
        $st = $pdo->prepare(
            'INSERT INTO tickets (code, title, meta, description, from_user_id, to_user_id, status, priority, is_cross, due_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, NOW())'
        );
        $st->execute([
            $code, $data['title'], $data['meta'] ?? null, $data['description'] ?? null,
            $data['from_user_id'], $data['to_user_id'], 'new', $data['priority'] ?? 'normal',
            $isCross, $data['due_at'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();
        TicketTimeline::add($id, 'สร้างและมอบหมายตั๋วงาน' . ($isCross ? ' (ข้ามขั้น)' : ''), $data['from_user_id']);
        return $id;
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT t.*, uf.full_name AS from_name, ut.full_name AS to_name
             FROM tickets t
             JOIN users uf ON uf.id = t.from_user_id
             JOIN users ut ON ut.id = t.to_user_id
             WHERE t.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $t = $st->fetch();
        return $t ?: null;
    }

    /**
     * @param array{status?:string,cross?:bool,q?:string,forUserId?:int} $filters
     */
    public static function search(array $filters): array
    {
        $sql = 'SELECT t.*, uf.full_name AS from_name, ut.full_name AS to_name
                FROM tickets t
                JOIN users uf ON uf.id = t.from_user_id
                JOIN users ut ON ut.id = t.to_user_id
                WHERE 1=1';
        $params = [];

        switch ($filters['filter'] ?? 'all') {
            case 'open':
                $sql .= " AND t.status IN ('new','ack','doing','review')";
                break;
            case 'wait':
                $sql .= " AND t.status IN ('submitted','review')";
                break;
            case 'done':
                $sql .= " AND t.status IN ('done','forced')";
                break;
            case 'cross':
                $sql .= ' AND t.is_cross = 1';
                break;
        }

        if (!empty($filters['q'])) {
            $sql .= ' AND (t.code LIKE ? OR t.title LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY t.created_at DESC';

        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Tickets with a due date in the given month that the user assigned or received.
     * When $unitType/$unitId are given, only include tickets where the *other* party
     * (the counterparty) is a member of that org unit in user_role_units.
     */
    public static function forMonth(int $userId, int $year, int $month, ?string $unitType = null, ?int $unitId = null): array
    {
        $unitWhere = '';
        $params = [$year, $month, $userId, $userId];

        if ($unitType !== null && $unitId !== null && isset(OrgUnit::TYPES[$unitType])) {
            $col = OrgUnit::TYPES[$unitType]['column']; // safe: from hardcoded map
            $unitWhere = " AND (
                (t.from_user_id = ? AND EXISTS (SELECT 1 FROM user_role_units WHERE user_id = t.to_user_id   AND {$col} = ?))
             OR (t.to_user_id   = ? AND EXISTS (SELECT 1 FROM user_role_units WHERE user_id = t.from_user_id AND {$col} = ?))
            )";
            array_push($params, $userId, $unitId, $userId, $unitId);
        }

        $st = Database::pdo()->prepare(
            "SELECT t.*, uf.full_name AS from_name, ut.full_name AS to_name
             FROM tickets t
             JOIN users uf ON uf.id = t.from_user_id
             JOIN users ut ON ut.id = t.to_user_id
             WHERE t.due_at IS NOT NULL
               AND YEAR(t.due_at) = ? AND MONTH(t.due_at) = ?
               AND (t.from_user_id = ? OR t.to_user_id = ?)
               {$unitWhere}
             ORDER BY t.due_at ASC"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Due-date alerts for one user, in two escalating levels.
     *
     * A ticket only alerts if there was ever room to act on it: tickets whose
     * whole lifetime (created_at → due_at) already fitted inside the alert
     * window were short-notice by design, so warning about them is noise and
     * they are excluded. That exclusion is applied per level, so a 2-day task
     * is exempt from the 3-day warning but still raises the 24-hour alert.
     *
     * @return array{urgent:array<int,array>,warning:array<int,array>}
     */
    public static function alertsFor(int $userId, int $warnDays, int $urgentHours): array
    {
        $st = Database::pdo()->prepare(
            "SELECT t.*, uf.full_name AS from_name, ut.full_name AS to_name
             FROM tickets t
             JOIN users uf ON uf.id = t.from_user_id
             JOIN users ut ON ut.id = t.to_user_id
             WHERE t.status NOT IN ('done','forced')
               AND t.due_at IS NOT NULL
               AND t.due_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
               AND (t.from_user_id = ? OR t.to_user_id = ?)
             ORDER BY t.due_at ASC"
        );
        $st->execute([max(1, $warnDays), $userId, $userId]);

        $warnHours = max(1, $warnDays) * 24;
        $now = time();
        $out = ['urgent' => [], 'warning' => []];

        foreach ($st->fetchAll() as $t) {
            $due = strtotime((string) $t['due_at']);
            $created = strtotime((string) $t['created_at']);
            $leadHours = ($due - $created) / 3600;
            $hoursLeft = ($due - $now) / 3600;

            $t['hoursLeft'] = $hoursLeft;
            $t['isOverdue'] = $hoursLeft < 0;
            $t['isMine'] = (int) $t['to_user_id'] === $userId;

            if ($hoursLeft <= $urgentHours) {
                if ($leadHours > $urgentHours) {
                    $out['urgent'][] = $t;
                }
            } elseif ($leadHours > $warnHours) {
                $out['warning'][] = $t;
            }
        }

        return $out;
    }

    public static function stats(): array
    {
        $pdo = Database::pdo();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
        $doing = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('ack','doing','review')")->fetchColumn();
        $done = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('done','forced')")->fetchColumn();
        $cross = (int) $pdo->query('SELECT COUNT(*) FROM tickets WHERE is_cross = 1')->fetchColumn();
        $overdue = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE due_at IS NOT NULL AND due_at < NOW() AND status NOT IN ('done','forced')")->fetchColumn();
        return compact('total', 'doing', 'done', 'cross', 'overdue');
    }

    public static function markOpenedIfNeeded(int $id, int $viewerId): void
    {
        $t = self::find($id);
        if ($t && $t['opened_at'] === null && (int) $t['to_user_id'] === $viewerId) {
            $st = Database::pdo()->prepare('UPDATE tickets SET opened_at = NOW() WHERE id = ?');
            $st->execute([$id]);
            TicketTimeline::add($id, 'ผู้รับมอบหมายเปิดอ่านครั้งแรก', $viewerId);
        }
    }

    public static function setStatus(int $id, string $status, int $byUserId, string $label): void
    {
        $pdo = Database::pdo();
        $fields = ['status = ?'];
        $params = [$status];
        $now = 'NOW()';
        switch ($status) {
            case 'ack':
                $fields[] = 'ack_at = COALESCE(ack_at, NOW())';
                break;
            case 'doing':
                $fields[] = 'doing_at = COALESCE(doing_at, NOW())';
                break;
            case 'submitted':
                $fields[] = 'submitted_at = NOW()';
                break;
            case 'done':
            case 'forced':
                $fields[] = 'closed_at = NOW()';
                break;
        }
        $sql = 'UPDATE tickets SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $params[] = $id;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        TicketTimeline::add($id, $label, $byUserId);
    }

    public static function reassign(int $id, int $newToUserId, string $toRole, int $byUserId): bool
    {
        $t = self::find($id);
        if (!$t) {
            return false;
        }
        $fromRole = $_SESSION['active_role'] ?? 'staff';
        $isCross = Role::isCrossLevel($fromRole, $toRole) ? 1 : 0;
        $st = Database::pdo()->prepare('UPDATE tickets SET to_user_id = ?, is_cross = ?, opened_at = NULL, ack_at = NULL, doing_at = NULL, status = "new" WHERE id = ?');
        $st->execute([$newToUserId, $isCross, $id]);
        $newTo = User::findById($newToUserId);
        TicketTimeline::add($id, 'มอบหมายต่อให้ ' . ($newTo['full_name'] ?? '#' . $newToUserId) . ($isCross ? ' (ข้ามขั้น)' : ''), $byUserId);
        return true;
    }

    /** Human-readable elapsed time since creation (or since closed, if closed). */
    public static function elapsedLabel(array $t): string
    {
        $start = new \DateTimeImmutable($t['created_at']);
        $end = $t['closed_at'] ? new \DateTimeImmutable($t['closed_at']) : new \DateTimeImmutable();
        return self::diffLabel($start, $end);
    }

    public static function diffLabel(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $diff = $from->diff($to);
        $parts = [];
        if ($diff->days > 0) {
            $parts[] = $diff->days . ' วัน';
        }
        if ($diff->h > 0) {
            $parts[] = $diff->h . ' ชม.';
        }
        if (!$parts) {
            $parts[] = max(1, $diff->i) . ' นาที';
        } elseif ($diff->days === 0 && $diff->i > 0) {
            $parts[] = $diff->i . ' นาที';
        }
        return implode(' ', $parts);
    }

    public static function durations(array $t): array
    {
        $out = [];
        $created = new \DateTimeImmutable($t['created_at']);
        if ($t['opened_at']) {
            $out[] = ['label' => 'เวลาที่ใช้ในการเปิดอ่าน', 'value' => self::diffLabel($created, new \DateTimeImmutable($t['opened_at']))];
        }
        if ($t['ack_at']) {
            $out[] = ['label' => 'เวลาที่ใช้ในการตอบรับงาน', 'value' => self::diffLabel($created, new \DateTimeImmutable($t['ack_at']))];
        }
        $out[] = ['label' => 'เวลารวมในการดำเนินการ', 'value' => self::elapsedLabel($t)];
        if ($t['due_at'] && !$t['closed_at']) {
            $now = new \DateTimeImmutable();
            $due = new \DateTimeImmutable($t['due_at']);
            $label = $due < $now ? 'เกินกำหนดมาแล้ว' : 'คงเหลือถึงกำหนดส่ง';
            $out[] = ['label' => $label, 'value' => self::diffLabel($now, $due)];
        }
        return $out;
    }
}

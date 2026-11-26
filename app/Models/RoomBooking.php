<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;

/**
 * A request to use a meeting room over a date range (start_date … end_date)
 * at the same time window each day (start_time … end_time). Anyone may ask;
 * it takes effect only once an approver (see isApprover) approves it.
 *
 * Two bookings clash when their date ranges overlap AND their daily time
 * windows overlap. Only approved bookings block: a request clashing with an
 * approved one is refused, a request clashing with another pending one is
 * allowed (the approver sees the clash and picks).
 */
final class RoomBooking
{
    /** status => [label, background, foreground, icon] */
    public const STATUS = [
        'pending'   => ['รออนุมัติ', '#fef3c7', '#92400e', 'hourglass-split'],
        'approved'  => ['อนุมัติแล้ว', '#dcfce7', '#166534', 'check-circle-fill'],
        'rejected'  => ['ไม่อนุมัติ', '#fee2e2', '#991b1b', 'x-circle-fill'],
        'cancelled' => ['ยกเลิกแล้ว', '#e2e8f0', '#475569', 'slash-circle'],
    ];

    /** Longest date range a single request may span. */
    public const MAX_DAYS = 31;

    /** Roles of the media งาน that may approve bookings. */
    public const APPROVER_ROLES = ['supervisor', 'staff'];

    public const SETTING_MEDIA_WORK = 'room_media_work_id';

    /** The tables arrive by migration; until it runs the pages show a notice instead of a 500. */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = (bool) Database::pdo()->query("SHOW TABLES LIKE 'room_bookings'")->fetchColumn();
        }
        return $ready;
    }

    // ------------------------------------------------------------------ approvers

    /**
     * The งาน whose หัวหน้างาน/เจ้าหน้าที่ approve bookings. Set by an admin on
     * /rooms/manage; until then, the first งาน whose name mentions สื่อ or โสต.
     */
    public static function mediaWorkId(): ?int
    {
        static $id = false;
        if ($id !== false) {
            return $id;
        }
        $set = (int) Setting::get(self::SETTING_MEDIA_WORK, '0');
        if ($set > 0 && OrgUnit::find('work', $set)) {
            return $id = $set;
        }
        $guess = Database::pdo()->query(
            "SELECT id FROM works WHERE name LIKE '%สื่อ%' OR name LIKE '%โสต%' ORDER BY id LIMIT 1"
        )->fetchColumn();
        return $id = ($guess ? (int) $guess : null);
    }

    public static function mediaWorkIsGuessed(): bool
    {
        return (int) Setting::get(self::SETTING_MEDIA_WORK, '0') <= 0;
    }

    /**
     * Approvers hold the role itself (not just have it active): a หัวหน้างาน of
     * the media งาน who has switched to their ครู role can still approve.
     */
    public static function isApprover(int $userId): bool
    {
        if (in_array('admin', User::rolesFor($userId), true)) {
            return true;
        }
        $workId = self::mediaWorkId();
        if ($workId === null) {
            return false;
        }
        $in = implode(',', array_fill(0, count(self::APPROVER_ROLES), '?'));
        $st = Database::pdo()->prepare(
            "SELECT 1 FROM user_role_units u
             JOIN roles r ON r.id = u.role_id
             JOIN user_roles ur ON ur.user_id = u.user_id AND ur.role_id = u.role_id
             WHERE u.user_id = ? AND u.work_id = ? AND r.code IN ($in) LIMIT 1"
        );
        $st->execute(array_merge([$userId, $workId], self::APPROVER_ROLES));
        return (bool) $st->fetchColumn();
    }

    /** @return array<int,array{id:int,full_name:string,role:string}> people of the media งาน who approve */
    public static function approvers(): array
    {
        $workId = self::mediaWorkId();
        if ($workId === null) {
            return [];
        }
        $in = implode(',', array_fill(0, count(self::APPROVER_ROLES), '?'));
        $st = Database::pdo()->prepare(
            "SELECT DISTINCT us.id, us.full_name, r.short_label AS role
             FROM user_role_units u
             JOIN roles r ON r.id = u.role_id
             JOIN user_roles ur ON ur.user_id = u.user_id AND ur.role_id = u.role_id
             JOIN users us ON us.id = u.user_id
             WHERE u.work_id = ? AND r.code IN ($in) AND us.is_active = 1
             ORDER BY r.hierarchy_level, us.full_name"
        );
        $st->execute(array_merge([$workId], self::APPROVER_ROLES));
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------ read

    private const SELECT = 'SELECT b.*, r.name AS room_name, r.color AS room_color, r.location AS room_location,
                                   u.full_name AS user_name, d.full_name AS decider_name
                            FROM room_bookings b
                            JOIN meeting_rooms r ON r.id = b.room_id
                            JOIN users u ON u.id = b.user_id
                            LEFT JOIN users d ON d.id = b.decided_by';

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE b.id = ? LIMIT 1');
        $st->execute([$id]);
        $b = $st->fetch();
        if (!$b) {
            return null;
        }
        $b['equipment'] = self::equipmentFor([(int) $b['id']])[(int) $b['id']] ?? [];
        return $b;
    }

    /**
     * Pending + approved bookings touching [$from, $to] (dates, inclusive).
     *
     * @return array<int,array>
     */
    public static function between(string $from, string $to, ?int $roomId = null): array
    {
        $sql = self::SELECT . " WHERE b.status IN ('pending','approved') AND b.start_date <= ? AND b.end_date >= ?";
        $args = [$to, $from];
        if ($roomId) {
            $sql .= ' AND b.room_id = ?';
            $args[] = $roomId;
        }
        $st = Database::pdo()->prepare($sql . ' ORDER BY b.start_time, r.sort_order, r.name');
        $st->execute($args);
        return self::withEquipment($st->fetchAll());
    }

    /** @return array<int,array> a user's own requests, upcoming first then recent past */
    public static function forUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $st = Database::pdo()->prepare(
            self::SELECT . " WHERE b.user_id = ?
             ORDER BY (b.end_date < CURDATE()) ASC,
                      CASE WHEN b.end_date < CURDATE() THEN NULL ELSE b.start_date END ASC,
                      b.start_date DESC, b.start_time
             LIMIT {$limit}"
        );
        $st->execute([$userId]);
        return self::withEquipment($st->fetchAll());
    }

    /** @return array<int,array> requests waiting for a decision, soonest first (stale ones included so they can be cleared) */
    public static function pending(): array
    {
        $rows = Database::pdo()->query(self::SELECT . " WHERE b.status = 'pending' ORDER BY b.start_date, b.start_time")->fetchAll();
        return self::withEquipment($rows);
    }

    public static function pendingCount(): int
    {
        return (int) Database::pdo()->query(
            "SELECT COUNT(*) FROM room_bookings WHERE status = 'pending' AND end_date >= CURDATE()"
        )->fetchColumn();
    }

    /** @return array<int,array> decisions made recently, for the approver's history list */
    public static function recentlyDecided(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $rows = Database::pdo()->query(
            self::SELECT . " WHERE b.status IN ('approved','rejected') AND b.decided_at IS NOT NULL
             ORDER BY b.decided_at DESC LIMIT {$limit}"
        )->fetchAll();
        return self::withEquipment($rows);
    }

    /**
     * Bookings in the same room whose dates and daily times overlap.
     *
     * @param string[] $statuses
     * @return array<int,array>
     */
    public static function clashes(int $roomId, string $startDate, string $endDate, string $startTime, string $endTime, ?int $excludeId = null, array $statuses = ['approved']): array
    {
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $st = Database::pdo()->prepare(
            self::SELECT . " WHERE b.room_id = ? AND b.status IN ($in) AND b.id <> ?
               AND b.start_date <= ? AND b.end_date >= ?
               AND b.start_time < ? AND b.end_time > ?
             ORDER BY b.start_date, b.start_time"
        );
        $st->execute(array_merge([$roomId], $statuses, [$excludeId ?? 0, $endDate, $startDate, $endTime, $startTime]));
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------ write

    /**
     * Validate a request. Returns the normalised fields, or a Thai error message.
     *
     * @return array|string
     */
    public static function validate(array $in, array $rooms): array|string
    {
        $roomId = (int) ($in['room_id'] ?? 0);
        $room = $rooms[$roomId] ?? null;
        if (!$room || !(int) $room['is_active']) {
            return 'กรุณาเลือกห้องประชุม';
        }
        $purpose = trim((string) ($in['purpose'] ?? ''));
        if ($purpose === '') {
            return 'กรุณาระบุวัตถุประสงค์การใช้ห้อง';
        }
        if (mb_strlen($purpose) > 255) {
            return 'วัตถุประสงค์ยาวเกินไป (ไม่เกิน 255 ตัวอักษร)';
        }

        $startDate = self::parseDate((string) ($in['start_date'] ?? ''));
        $endRaw = trim((string) ($in['end_date'] ?? ''));
        $endDate = $endRaw === '' ? $startDate : self::parseDate($endRaw);
        if (!$startDate || !$endDate) {
            return 'กรุณาระบุวันที่ขอใช้ให้ถูกต้อง';
        }
        if ($endDate < $startDate) {
            return 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มต้น';
        }
        $days = (int) (new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days + 1;
        if ($days > self::MAX_DAYS) {
            return 'ขอใช้ได้ครั้งละไม่เกิน ' . self::MAX_DAYS . ' วัน';
        }

        $startTime = self::parseTime((string) ($in['start_time'] ?? ''));
        $endTime = self::parseTime((string) ($in['end_time'] ?? ''));
        if (!$startTime || !$endTime) {
            return 'กรุณาระบุเวลาที่ขอใช้ให้ถูกต้อง';
        }
        if ($endTime <= $startTime) {
            return 'เวลาสิ้นสุดต้องหลังเวลาเริ่มต้น';
        }
        if (new DateTimeImmutable($startDate . ' ' . $startTime) < new DateTimeImmutable('-5 minutes')) {
            return 'ไม่สามารถขอใช้ห้องย้อนหลังได้';
        }

        $attendees = trim((string) ($in['attendees'] ?? ''));
        $attendees = $attendees === '' ? null : (int) $attendees;
        if ($attendees !== null && $attendees < 1) {
            return 'จำนวนผู้เข้าร่วมไม่ถูกต้อง';
        }
        if ($attendees !== null && $room['capacity'] && $attendees > (int) $room['capacity']) {
            return 'จำนวนผู้เข้าร่วมเกินความจุของห้อง (' . (int) $room['capacity'] . ' คน)';
        }

        $equipment = [];
        foreach ((array) ($in['equipment'] ?? []) as $eid) {
            $eid = (int) $eid;
            if (!isset($room['equipment'][$eid])) {
                return 'มีอุปกรณ์ที่ห้องนี้ไม่มีให้บริการ กรุณาเลือกใหม่';
            }
            $equipment[$eid] = $eid;
        }

        $clash = self::clashes($roomId, $startDate, $endDate, $startTime, $endTime);
        if ($clash) {
            $c = $clash[0];
            return 'ช่วงเวลานี้ห้อง "' . $room['name'] . '" ถูกจองแล้ว: ' . $c['purpose']
                . ' (' . self::rangeLabel($c) . ')';
        }

        return [
            'room_id' => $roomId,
            'purpose' => $purpose,
            'details' => trim((string) ($in['details'] ?? '')) ?: null,
            'attendees' => $attendees,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'equipment' => array_values($equipment),
        ];
    }

    public static function create(int $userId, array $v): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO room_bookings (room_id, user_id, purpose, details, attendees, start_date, end_date, start_time, end_time)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $v['room_id'], $userId, $v['purpose'], $v['details'], $v['attendees'],
                $v['start_date'], $v['end_date'], $v['start_time'], $v['end_time'],
            ]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO room_booking_equipment (booking_id, equipment_id) VALUES (?,?)');
            foreach ($v['equipment'] as $eid) {
                $ins->execute([$id, $eid]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /**
     * Approve a pending request. The room row is locked so two approvers
     * can't each approve one of two clashing requests at the same moment.
     * Returns null on success or a Thai error message.
     */
    public static function approve(int $id, int $deciderId, ?string $note): ?string
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Locking reads only: InnoDB takes the transaction's snapshot at the
            // first plain SELECT, which must come after the room lock so the
            // clash check sees an approval another approver just committed.
            $st = $pdo->prepare('SELECT * FROM room_bookings WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $b = $st->fetch();
            if (!$b || $b['status'] !== 'pending') {
                $pdo->rollBack();
                return 'คำขอนี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว';
            }
            $pdo->prepare('SELECT id FROM meeting_rooms WHERE id = ? FOR UPDATE')->execute([$b['room_id']]);
            $clash = self::clashes((int) $b['room_id'], $b['start_date'], $b['end_date'], $b['start_time'], $b['end_time'], $id);
            if ($clash) {
                $pdo->rollBack();
                return 'อนุมัติไม่ได้ ช่วงเวลานี้ทับกับการจองที่อนุมัติแล้ว: ' . $clash[0]['purpose'] . ' (' . self::rangeLabel($clash[0]) . ')';
            }
            $pdo->prepare(
                "UPDATE room_bookings SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ?
                 WHERE id = ? AND status = 'pending'"
            )->execute([$deciderId, $note, $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return null;
    }

    public static function reject(int $id, int $deciderId, string $note): bool
    {
        $st = Database::pdo()->prepare(
            "UPDATE room_bookings SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_note = ?
             WHERE id = ? AND status = 'pending'"
        );
        $st->execute([$deciderId, $note, $id]);
        return $st->rowCount() > 0;
    }

    /** The requester withdraws, or an approver revokes an approved booking (note says why). */
    public static function cancel(int $id, int $byUserId, ?string $note): bool
    {
        $st = Database::pdo()->prepare(
            "UPDATE room_bookings SET status = 'cancelled', decided_by = ?, decided_at = NOW(),
                    decision_note = COALESCE(?, decision_note)
             WHERE id = ? AND status IN ('pending','approved')"
        );
        $st->execute([$byUserId, $note, $id]);
        return $st->rowCount() > 0;
    }

    // ------------------------------------------------------------------ display helpers

    private const THAI_MONTHS_SHORT = [1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    private const THAI_DAYS_SHORT = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

    public static function thaiDate(string $date, bool $withDay = false): string
    {
        $d = new DateTimeImmutable($date);
        $s = (int) $d->format('j') . ' ' . self::THAI_MONTHS_SHORT[(int) $d->format('n')] . ' ' . ((int) $d->format('Y') + 543);
        return $withDay ? self::THAI_DAYS_SHORT[(int) $d->format('w')] . ' ' . $s : $s;
    }

    public static function timeLabel(array $b): string
    {
        return substr((string) $b['start_time'], 0, 5) . '–' . substr((string) $b['end_time'], 0, 5) . ' น.';
    }

    /** "3 ธ.ค. 2569 09:00–12:00 น." or "3 – 5 ธ.ค. 2569 …" for a range. */
    public static function rangeLabel(array $b): string
    {
        $date = $b['start_date'] === $b['end_date']
            ? self::thaiDate($b['start_date'], true)
            : self::thaiDate($b['start_date'], true) . ' – ' . self::thaiDate($b['end_date'], true);
        return $date . ' ' . self::timeLabel($b);
    }

    public static function dayCount(array $b): int
    {
        return (int) (new DateTimeImmutable($b['start_date']))->diff(new DateTimeImmutable($b['end_date']))->days + 1;
    }

    // ------------------------------------------------------------------ private

    private static function withEquipment(array $rows): array
    {
        $map = self::equipmentFor(array_map(fn($r) => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['equipment'] = $map[(int) $r['id']] ?? [];
        }
        return $rows;
    }

    /** @return array<int,array<int,array>> booking id => requested equipment */
    private static function equipmentFor(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT be.booking_id, e.id, e.name, e.icon
             FROM room_booking_equipment be JOIN room_equipment e ON e.id = be.equipment_id
             WHERE be.booking_id IN ($in) ORDER BY e.sort_order, e.name"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int) $row['booking_id']][] = $row;
        }
        return $out;
    }

    private static function parseDate(string $s): ?string
    {
        $s = trim($s);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s ? $s : null;
    }

    private static function parseTime(string $s): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', trim($s), $m) ? $m[1] . ':' . $m[2] . ':00' : null;
    }
}

<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Field-level history of edits made to a ticket after it was created.
 *
 * An edit made after the assignee has opened the ticket is addressed to them
 * (notify_user_id) and stays "unseen" — in their bell and as a highlighted
 * box on the ticket — until they acknowledge it. Edits made before they ever
 * opened it need no notice: they'll simply read the current version.
 */
final class TicketEdit
{
    /** Editable ticket fields and their Thai labels, in display order. */
    public const FIELDS = [
        'title' => 'หัวข้องาน',
        'meta' => 'หน่วยงาน / หมายเหตุสั้น',
        'priority' => 'ความสำคัญ',
        'due_at' => 'กำหนดส่ง',
        'description' => 'รายละเอียดงาน',
    ];

    /**
     * Compare the stored ticket with submitted values.
     *
     * @param array<string,?string> $new normalised new values keyed by field
     * @return array<int,array{field:string,label:string,old:?string,new:?string}>
     */
    public static function diff(array $ticket, array $new): array
    {
        $changes = [];
        foreach (self::FIELDS as $field => $label) {
            if (!array_key_exists($field, $new)) {
                continue;
            }
            $old = self::norm($field, $ticket[$field] ?? null);
            $nv = self::norm($field, $new[$field]);
            if ($old !== $nv) {
                $changes[] = ['field' => $field, 'label' => $label, 'old' => $old, 'new' => $nv];
            }
        }
        return $changes;
    }

    private static function norm(string $field, ?string $v): ?string
    {
        $v = $v === null ? null : trim(str_replace("\r\n", "\n", $v));
        if ($v === '' || $v === null) {
            return null;
        }
        if ($field === 'due_at') {
            $ts = strtotime($v);
            return $ts ? date('Y-m-d H:i:00', $ts) : null;
        }
        return $v;
    }

    /** A value as people should read it (priority label, Thai date, "—"). */
    public static function display(string $field, ?string $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if ($field === 'priority') {
            return Ticket::PRIORITY[$v][0] ?? $v;
        }
        if ($field === 'due_at') {
            return date('d/m/Y H:i', strtotime($v));
        }
        return $v;
    }

    /**
     * Whether the ticket_edits table exists. A push deploys the code at once,
     * but the migration only runs when an admin clicks it on
     * /admin/migrations — until then every read here returns nothing instead
     * of breaking the layout's bell on every page.
     */
    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = (bool) Database::pdo()->query("SHOW TABLES LIKE 'ticket_edits'")->fetchColumn();
        }
        return $ready;
    }

    public static function record(int $ticketId, int $byUserId, ?int $notifyUserId, array $changes): void
    {
        Database::pdo()->prepare(
            'INSERT INTO ticket_edits (ticket_id, by_user_id, notify_user_id, changes, at) VALUES (?,?,?,?, NOW())'
        )->execute([$ticketId, $byUserId, $notifyUserId, json_encode($changes, JSON_UNESCAPED_UNICODE)]);
    }

    /** @return array<int,array> newest first, with decoded `changes` and `by_name` */
    public static function forTicket(int $ticketId): array
    {
        if (!self::ready()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT e.*, u.full_name AS by_name, n.full_name AS notify_name
             FROM ticket_edits e
             JOIN users u ON u.id = e.by_user_id
             LEFT JOIN users n ON n.id = e.notify_user_id
             WHERE e.ticket_id = ? ORDER BY e.at DESC, e.id DESC'
        );
        $st->execute([$ticketId]);
        return array_map([self::class, 'decode'], $st->fetchAll());
    }

    /** Unacknowledged edits on one ticket addressed to this user. */
    public static function unseenForTicket(int $ticketId, int $userId): array
    {
        return array_values(array_filter(
            self::forTicket($ticketId),
            fn($e) => (int) $e['notify_user_id'] === $userId && $e['seen_at'] === null
        ));
    }

    /**
     * Tickets with unacknowledged edits for this user (for the bell), one row
     * per ticket with the fields changed across all its pending edits.
     *
     * @return array<int,array>
     */
    public static function unseenFor(int $userId): array
    {
        if (!self::ready()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT e.ticket_id, e.changes, e.at, t.code, t.title, t.to_user_id, u.full_name AS by_name
             FROM ticket_edits e
             JOIN tickets t ON t.id = e.ticket_id
             JOIN users u ON u.id = e.by_user_id
             WHERE e.notify_user_id = ? AND e.seen_at IS NULL
               AND t.to_user_id = e.notify_user_id
               AND t.status NOT IN ('done','forced')
             ORDER BY e.at DESC"
        );
        $st->execute([$userId]);

        $out = [];
        foreach ($st->fetchAll() as $row) {
            $tid = (int) $row['ticket_id'];
            if (!isset($out[$tid])) {
                $out[$tid] = [
                    'id' => $tid, 'code' => $row['code'], 'title' => $row['title'],
                    'by_name' => $row['by_name'], 'at' => $row['at'], 'labels' => [],
                ];
            }
            foreach (json_decode((string) $row['changes'], true) ?: [] as $c) {
                $out[$tid]['labels'][$c['label']] = true;
            }
        }
        foreach ($out as &$o) {
            $o['labels'] = array_keys($o['labels']);
        }
        return array_values($out);
    }

    /** @return int number of edits acknowledged */
    public static function markSeen(int $ticketId, int $userId): int
    {
        if (!self::ready()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'UPDATE ticket_edits SET seen_at = NOW() WHERE ticket_id = ? AND notify_user_id = ? AND seen_at IS NULL'
        );
        $st->execute([$ticketId, $userId]);
        return $st->rowCount();
    }

    private static function decode(array $row): array
    {
        $row['changes'] = json_decode((string) $row['changes'], true) ?: [];
        return $row;
    }
}

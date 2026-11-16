<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Edits an assigner makes to a ticket after it was assigned. When the
 * assignee had already opened the ticket, the row carries notify_user_id so
 * the assignee is shown exactly what changed until they acknowledge it.
 */
final class TicketChange
{
    private static ?bool $available = null;

    /**
     * Whether the ticket_changes migration has been run. Deploys reach
     * production before an admin runs pending migrations, and the bell in the
     * layout queries this table on every page — without this check every page
     * (including /admin/migrations itself) would fail until then.
     */
    public static function available(): bool
    {
        if (self::$available === null) {
            self::$available = Database::pdo()->query("SHOW TABLES LIKE 'ticket_changes'")->fetchColumn() !== false;
        }
        return self::$available;
    }

    /**
     * @param list<array{field:string,label:string,old:string,new:string}> $changes
     */
    public static function add(int $ticketId, int $byUserId, array $changes, ?int $notifyUserId): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO ticket_changes (ticket_id, changed_by, changed_at, changes, notify_user_id) VALUES (?,?, NOW(),?,?)'
        );
        $st->execute([$ticketId, $byUserId, json_encode($changes, JSON_UNESCAPED_UNICODE), $notifyUserId]);
    }

    /** Unacknowledged changes on one ticket for this user, oldest first. */
    public static function unseenFor(int $ticketId, int $userId): array
    {
        if (!self::available()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT c.*, u.full_name AS by_name FROM ticket_changes c
             JOIN users u ON u.id = c.changed_by
             WHERE c.ticket_id = ? AND c.notify_user_id = ? AND c.seen_at IS NULL
             ORDER BY c.changed_at ASC, c.id ASC'
        );
        $st->execute([$ticketId, $userId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['changes'] = json_decode((string) $r['changes'], true) ?: [];
        }
        return $rows;
    }

    /** Tickets with unacknowledged changes for this user (for the bell). */
    public static function ticketsWithUnseen(int $userId): array
    {
        if (!self::available()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT t.id, t.code, t.title, uf.full_name AS from_name,
                    COUNT(*) AS edits, MAX(c.changed_at) AS last_changed_at
             FROM ticket_changes c
             JOIN tickets t ON t.id = c.ticket_id
             JOIN users uf ON uf.id = t.from_user_id
             WHERE c.notify_user_id = ? AND c.seen_at IS NULL AND t.to_user_id = ?
             GROUP BY t.id, t.code, t.title, uf.full_name
             ORDER BY last_changed_at DESC'
        );
        $st->execute([$userId, $userId]);
        return $st->fetchAll();
    }

    /** @return int how many pending changes were acknowledged */
    public static function markSeen(int $ticketId, int $userId): int
    {
        if (!self::available()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'UPDATE ticket_changes SET seen_at = NOW() WHERE ticket_id = ? AND notify_user_id = ? AND seen_at IS NULL'
        );
        $st->execute([$ticketId, $userId]);
        return $st->rowCount();
    }
}

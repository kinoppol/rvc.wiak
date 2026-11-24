<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Per-user feed for the desktop notifier. Rows exist only for users with a
 * paired device. dedupe_key makes each alert fire once: a due-date alert is
 * keyed on the due date, so moving the deadline can alert again.
 */
final class Notification
{
    public const NEW_TICKET = 'new_ticket';
    public const REASSIGNED = 'reassigned';
    public const EDITED = 'edited';
    public const DUE_WARNING = 'due_warning';
    public const DUE_URGENT = 'due_urgent';
    public const OVERDUE = 'overdue';

    private const CLOSED = ['done', 'forced', 'cancelled'];
    private const RETENTION_DAYS = 90;

    /**
     * Record an event on a ticket. Never throws: a notification problem must
     * not fail the ticket action that has already been saved.
     */
    public static function ticketEvent(int $userId, int $ticketId, string $type, int $actorId): void
    {
        if ($userId === $actorId) {
            return;
        }
        try {
            if (!DesktopDevice::ready() || !DesktopDevice::hasActive($userId)) {
                return;
            }
            $key = $type === self::NEW_TICKET
                ? "new:{$ticketId}"
                : "{$type}:{$ticketId}:" . bin2hex(random_bytes(8));
            self::insert($userId, $ticketId, $type, $key, false);
        } catch (\Throwable $e) {
            error_log('Notification::ticketEvent failed: ' . get_class($e));
        }
    }

    private static function insert(int $userId, int $ticketId, string $type, string $key, bool $asRead): int
    {
        $st = Database::pdo()->prepare(
            'INSERT IGNORE INTO user_notifications (user_id, ticket_id, type, dedupe_key, read_at)
             VALUES (?,?,?,?, ' . ($asRead ? 'NOW()' : 'NULL') . ')'
        );
        $st->execute([$userId, $ticketId, $type, $key]);
        return $st->rowCount();
    }

    /**
     * Record the user's current due-date alerts, using the bell's own rules.
     * With $asRead (at pairing) they are stored as already seen, so a new
     * device isn't flooded with its whole backlog.
     */
    public static function recordDue(array $user, bool $asRead = false): int
    {
        $alerts = Ticket::alertsFor(
            (int) $user['id'],
            User::warnDaysFor($user),
            max(1, (int) Setting::get('notify_urgent_hours', '24'))
        );
        $added = 0;
        foreach (['urgent' => self::DUE_URGENT, 'warning' => self::DUE_WARNING] as $level => $type) {
            foreach ($alerts[$level] as $t) {
                $actual = !empty($t['isOverdue']) ? self::OVERDUE : $type;
                $key = $actual . ':' . (int) $t['id'] . ':' . strtotime((string) $t['due_at']);
                $added += self::insert((int) $user['id'], (int) $t['id'], $actual, $key, $asRead);
            }
        }
        return $added;
    }

    /** Cron: due-date alerts for everyone with a paired device, then cleanup. */
    public static function generateDue(): array
    {
        $pdo = Database::pdo();
        $users = $pdo->query(
            'SELECT u.* FROM users u
             WHERE u.is_active = 1
               AND EXISTS (SELECT 1 FROM desktop_devices d WHERE d.user_id = u.id AND d.revoked_at IS NULL)'
        )->fetchAll();

        $added = 0;
        foreach ($users as $u) {
            $added += self::recordDue($u);
        }

        // Only closed tickets: an open one still needs its dedupe rows, or
        // an old overdue alert would fire again.
        $pruned = $pdo->exec(
            "DELETE n FROM user_notifications n JOIN tickets t ON t.id = n.ticket_id
             WHERE n.created_at < DATE_SUB(NOW(), INTERVAL " . self::RETENTION_DAYS . " DAY)
               AND t.status IN ('done','forced','cancelled')"
        );
        return ['users' => count($users), 'added' => $added, 'pruned' => (int) $pruned];
    }

    /**
     * Unread items after a cursor. Items whose ticket has closed or moved away
     * are skipped, but the cursor still passes them.
     *
     * @return array{items:array<int,array>,cursor:int,has_more:bool}
     */
    public static function feed(int $userId, int $after, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $st = Database::pdo()->prepare(
            'SELECT n.id, n.type, n.ticket_id, n.created_at,
                    t.code, t.title, t.priority, t.due_at, t.status, t.from_user_id, t.to_user_id,
                    uf.full_name AS from_name
             FROM user_notifications n
             JOIN tickets t ON t.id = n.ticket_id
             JOIN users uf ON uf.id = t.from_user_id
             WHERE n.user_id = ? AND n.id > ? AND n.read_at IS NULL
             ORDER BY n.id ASC LIMIT ' . ($limit + 1)
        );
        $st->execute([$userId, $after]);
        $rows = $st->fetchAll();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $items = [];
        $cursor = $after;
        foreach ($rows as $r) {
            $cursor = (int) $r['id'];
            if (in_array($r['status'], self::CLOSED, true) || !Ticket::involves($r, $userId)) {
                continue;
            }
            $items[] = self::present($r, $userId);
        }
        return ['items' => $items, 'cursor' => $cursor, 'has_more' => $hasMore];
    }

    private static function present(array $r, int $userId): array
    {
        $title = (string) $r['title'];
        $due = $r['due_at'] ? date('d/m/Y H:i', strtotime((string) $r['due_at'])) : null;
        $whose = (int) $r['to_user_id'] === $userId ? '' : 'งานที่คุณมอบหมาย: ';
        [$heading, $body] = match ($r['type']) {
            self::NEW_TICKET => ['งานใหม่', "{$r['from_name']} มอบหมาย: {$title}"],
            self::REASSIGNED => ['ได้รับมอบหมายงานต่อ', "{$r['from_name']} มอบหมาย: {$title}"],
            self::EDITED => ['งานถูกแก้ไข', "{$r['from_name']} แก้ไข: {$title}"],
            self::DUE_WARNING => ['งานใกล้ถึงกำหนด', "{$whose}{$title} (กำหนดส่ง {$due})"],
            self::DUE_URGENT => ['งานด่วน ใกล้ถึงกำหนด', "{$whose}{$title} (กำหนดส่ง {$due})"],
            self::OVERDUE => ['งานเกินกำหนด', "{$whose}{$title} (กำหนดส่ง {$due})"],
            default => ['แจ้งเตือน', $title],
        };
        return [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'heading' => $heading,
            'body' => $body,
            'ticket' => [
                'id' => (int) $r['ticket_id'],
                'code' => $r['code'],
                'title' => $title,
                'priority' => $r['priority'],
                'status' => $r['status'],
                'due_at' => $r['due_at'],
            ],
            'open_path' => '/open/ticket/' . (int) $r['ticket_id'],
            'created_at' => $r['created_at'],
        ];
    }

    public static function unreadCount(int $userId): int
    {
        $st = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM user_notifications n
             JOIN tickets t ON t.id = n.ticket_id
             WHERE n.user_id = ? AND n.read_at IS NULL
               AND t.status NOT IN ('done','forced','cancelled')
               AND (t.from_user_id = n.user_id OR t.to_user_id = n.user_id)"
        );
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /** @param int[] $ids */
    public static function markRead(int $userId, array $ids): int
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0))), 0, 200);
        if (!$ids) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN ({$marks})");
        $st->execute([$userId, ...$ids]);
        return $st->rowCount();
    }

    public static function markAllRead(int $userId): int
    {
        $st = Database::pdo()->prepare('UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $st->execute([$userId]);
        return $st->rowCount();
    }

    /** Opening a ticket on the web clears its alerts in the tray too. */
    public static function markTicketRead(int $ticketId, int $userId): void
    {
        try {
            if (!DesktopDevice::ready()) {
                return;
            }
            Database::pdo()->prepare('UPDATE user_notifications SET read_at = NOW() WHERE ticket_id = ? AND user_id = ? AND read_at IS NULL')
                ->execute([$ticketId, $userId]);
        } catch (\Throwable $e) {
            error_log('Notification::markTicketRead failed: ' . get_class($e));
        }
    }

    public static function latestId(int $userId): int
    {
        $st = Database::pdo()->prepare('SELECT COALESCE(MAX(id), 0) FROM user_notifications WHERE user_id = ?');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }
}

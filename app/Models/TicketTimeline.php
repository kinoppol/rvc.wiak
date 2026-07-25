<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class TicketTimeline
{
    public static function add(int $ticketId, string $label, ?int $byUserId): void
    {
        $st = Database::pdo()->prepare('INSERT INTO ticket_timeline (ticket_id, label, by_user_id, at) VALUES (?,?,?, NOW())');
        $st->execute([$ticketId, $label, $byUserId]);
    }

    public static function forTicket(int $ticketId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT tl.*, u.full_name AS by_name FROM ticket_timeline tl
             LEFT JOIN users u ON u.id = tl.by_user_id
             WHERE tl.ticket_id = ? ORDER BY tl.at ASC, tl.id ASC'
        );
        $st->execute([$ticketId]);
        $rows = $st->fetchAll();
        $prev = null;
        foreach ($rows as &$r) {
            $at = new \DateTimeImmutable($r['at']);
            $r['gap'] = $prev ? '+' . Ticket::diffLabel($prev, $at) . ' หลังรายการก่อนหน้า' : '';
            $prev = $at;
        }
        return $rows;
    }
}

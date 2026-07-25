<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class TicketQuestion
{
    public static function add(int $ticketId, string $text, int $byUserId): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COALESCE(MAX(no),0)+1 FROM ticket_questions WHERE ticket_id = ?');
        $st->execute([$ticketId]);
        $no = (int) $st->fetchColumn();

        $ins = $pdo->prepare('INSERT INTO ticket_questions (ticket_id, no, text, by_user_id, at) VALUES (?,?,?,?, NOW())');
        $ins->execute([$ticketId, $no, $text, $byUserId]);
        return (int) $pdo->lastInsertId();
    }

    public static function answer(int $questionId, string $answer, int $byUserId): bool
    {
        $st = Database::pdo()->prepare('UPDATE ticket_questions SET answer = ?, answer_by_user_id = ?, answer_at = NOW() WHERE id = ? AND answer IS NULL');
        $st->execute([$answer, $byUserId, $questionId]);
        return $st->rowCount() > 0;
    }

    public static function forTicket(int $ticketId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT q.*, ub.full_name AS by_name, ua.full_name AS answer_by_name
             FROM ticket_questions q
             JOIN users ub ON ub.id = q.by_user_id
             LEFT JOIN users ua ON ua.id = q.answer_by_user_id
             WHERE q.ticket_id = ? ORDER BY q.no ASC'
        );
        $st->execute([$ticketId]);
        return $st->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM ticket_questions WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $q = $st->fetch();
        return $q ?: null;
    }
}

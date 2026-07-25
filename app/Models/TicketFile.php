<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Upload;

final class TicketFile
{
    public static function addUpload(int $ticketId, array $stored, int $byUserId): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO ticket_files (ticket_id, name, is_link, stored_path, mime, size_bytes, uploaded_by, uploaded_at)
             VALUES (?,?,0,?,?,?,?, NOW())'
        );
        $st->execute([$ticketId, $stored['name'], $stored['storedPath'], $stored['mime'], $stored['size'], $byUserId]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function addLink(int $ticketId, string $name, string $url, int $byUserId): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO ticket_files (ticket_id, name, is_link, url, uploaded_by, uploaded_at) VALUES (?,?,1,?,?, NOW())'
        );
        $st->execute([$ticketId, $name, $url, $byUserId]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function forTicket(int $ticketId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM ticket_files WHERE ticket_id = ? ORDER BY uploaded_at ASC');
        $st->execute([$ticketId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            if ($r['is_link']) {
                $r['icon'] = 'link-45deg';
                $r['color'] = '#2563eb';
                $r['meta'] = 'ลิงก์ภายนอก · ' . $r['url'];
            } else {
                [$icon, $color] = Upload::iconFor((string) $r['mime'], (string) $r['name']);
                $r['icon'] = $icon;
                $r['color'] = $color;
                $r['meta'] = strtoupper(pathinfo($r['name'], PATHINFO_EXTENSION)) . ' · ' . Upload::humanSize((int) $r['size_bytes']);
            }
        }
        return $rows;
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM ticket_files WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $f = $st->fetch();
        return $f ?: null;
    }
}

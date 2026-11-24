<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\View;

/**
 * Direct messages between two users. A message may mention tickets as
 * "#TK-2569-008". A mention becomes a link only for a reader who may open that
 * ticket. Others see the bare code, never its title.
 */
final class Chat
{
    public const MAX_BODY = 4000;
    public const CODE_PATTERN = '/#(TK-\d{4}-\d{3,6})\b/';

    public static function ready(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = (bool) Database::pdo()->query("SHOW TABLES LIKE 'chat_messages'")->fetchColumn();
        }
        return $ready;
    }

    /** The one conversation between two users, created on first use. */
    public static function directWith(int $me, int $other): int
    {
        $key = min($me, $other) . ':' . max($me, $other);
        $pdo = Database::pdo();
        $find = $pdo->prepare('SELECT id FROM chat_conversations WHERE pair_key = ?');
        $find->execute([$key]);
        if ($id = $find->fetchColumn()) {
            return (int) $id;
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO chat_conversations (pair_key) VALUES (?)')->execute([$key]);
            $id = (int) $pdo->lastInsertId();
            $add = $pdo->prepare('INSERT INTO chat_members (conversation_id, user_id) VALUES (?, ?)');
            $add->execute([$id, $me]);
            $add->execute([$id, $other]);
            $pdo->commit();
            return $id;
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Both people started the same conversation at once.
            $find->execute([$key]);
            if ($id = $find->fetchColumn()) {
                return (int) $id;
            }
            throw $e;
        }
    }

    public static function isMember(int $conversationId, int $userId): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM chat_members WHERE conversation_id = ? AND user_id = ?');
        $st->execute([$conversationId, $userId]);
        return (bool) $st->fetchColumn();
    }

    /** The other person in a conversation (id, full_name, avatar_path). */
    public static function otherMember(int $conversationId, int $me): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT u.id, u.full_name, u.avatar_path FROM chat_members m JOIN users u ON u.id = m.user_id
             WHERE m.conversation_id = ? AND m.user_id <> ? LIMIT 1'
        );
        $st->execute([$conversationId, $me]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array> newest activity first */
    public static function conversationsFor(int $userId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT c.id, COALESCE(c.last_message_at, c.created_at) AS activity_at,
                    m.body AS last_body, m.sender_id AS last_sender,
                    u.id AS other_id, u.full_name AS other_name, u.avatar_path AS other_avatar,
                    (SELECT COUNT(*) FROM chat_messages x
                      WHERE x.conversation_id = c.id AND x.id > me.last_read_id AND x.sender_id <> me.user_id) AS unread
             FROM chat_members me
             JOIN chat_conversations c ON c.id = me.conversation_id
             JOIN chat_members o ON o.conversation_id = c.id AND o.user_id <> me.user_id
             JOIN users u ON u.id = o.user_id
             LEFT JOIN chat_messages m ON m.id = c.last_message_id
             WHERE me.user_id = ?
             ORDER BY activity_at DESC, c.id DESC'
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /**
     * Messages after $afterId, or the latest $limit when $afterId is 0.
     *
     * @return array<int,array> oldest first, with sender_name
     */
    public static function messages(int $conversationId, int $afterId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $pdo = Database::pdo();
        if ($afterId > 0) {
            $st = $pdo->prepare(
                "SELECT m.*, u.full_name AS sender_name FROM chat_messages m JOIN users u ON u.id = m.sender_id
                 WHERE m.conversation_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT {$limit}"
            );
            $st->execute([$conversationId, $afterId]);
            return $st->fetchAll();
        }
        $st = $pdo->prepare(
            "SELECT * FROM (
                SELECT m.*, u.full_name AS sender_name FROM chat_messages m JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = ? ORDER BY m.id DESC LIMIT {$limit}
             ) latest ORDER BY id ASC"
        );
        $st->execute([$conversationId]);
        return $st->fetchAll();
    }

    public static function send(int $conversationId, int $senderId, string $body): int
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO chat_messages (conversation_id, sender_id, body) VALUES (?,?,?)')
            ->execute([$conversationId, $senderId, $body]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE chat_conversations SET last_message_id = ?, last_message_at = NOW() WHERE id = ?')
            ->execute([$id, $conversationId]);
        self::markRead($conversationId, $senderId, $id);
        return $id;
    }

    public static function markRead(int $conversationId, int $userId, int $upToId): void
    {
        Database::pdo()->prepare(
            'UPDATE chat_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conversation_id = ? AND user_id = ?'
        )->execute([$upToId, $conversationId, $userId]);
    }

    public static function unreadTotal(int $userId): int
    {
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM chat_members me
             JOIN chat_messages x ON x.conversation_id = me.conversation_id
             WHERE me.user_id = ? AND x.id > me.last_read_id AND x.sender_id <> me.user_id'
        );
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    public static function latestIdFor(int $userId): int
    {
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(x.id), 0) FROM chat_members me
             JOIN chat_messages x ON x.conversation_id = me.conversation_id WHERE me.user_id = ?'
        );
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Unread messages from others after a cursor, for the desktop app. The
     * cursor also passes the user's own messages.
     *
     * @return array{items:array<int,array>,cursor:int}
     */
    public static function feedForDevice(int $userId, int $after, int $limit = 50): array
    {
        $st = Database::pdo()->prepare(
            "SELECT x.id, x.conversation_id, x.sender_id, x.body, x.created_at, me.last_read_id, u.full_name AS sender_name
             FROM chat_members me
             JOIN chat_messages x ON x.conversation_id = me.conversation_id
             JOIN users u ON u.id = x.sender_id
             WHERE me.user_id = ? AND x.id > ?
             ORDER BY x.id ASC LIMIT " . max(1, min(100, $limit))
        );
        $st->execute([$userId, $after]);
        $items = [];
        $cursor = $after;
        foreach ($st->fetchAll() as $r) {
            $cursor = (int) $r['id'];
            if ((int) $r['sender_id'] === $userId || (int) $r['id'] <= (int) $r['last_read_id']) {
                continue;
            }
            $items[] = [
                'id' => (int) $r['id'],
                'conversation_id' => (int) $r['conversation_id'],
                'sender' => ['id' => (int) $r['sender_id'], 'full_name' => $r['sender_name']],
                'preview' => self::preview((string) $r['body']),
                'open_path' => '/chat/' . (int) $r['conversation_id'],
                'created_at' => $r['created_at'],
            ];
        }
        return ['items' => $items, 'cursor' => $cursor];
    }

    /** One line of plain text, for lists and toasts. */
    public static function preview(string $body, int $width = 120): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $body));
        return mb_strimwidth($line, 0, $width, '…', 'UTF-8');
    }

    /**
     * Tickets mentioned in these bodies, keyed by code. `visible` is whether
     * this viewer may open the ticket.
     *
     * @param string[] $bodies
     * @return array<string,array{id:int,title:string,visible:bool}>
     */
    public static function ticketRefs(array $bodies, int $viewerId, bool $viewerIsAdmin): array
    {
        $codes = [];
        foreach ($bodies as $b) {
            if (preg_match_all(self::CODE_PATTERN, $b, $m)) {
                foreach ($m[1] as $c) {
                    $codes[$c] = true;
                }
            }
        }
        if (!$codes) {
            return [];
        }
        $codes = array_slice(array_keys($codes), 0, 200);
        $marks = implode(',', array_fill(0, count($codes), '?'));
        $st = Database::pdo()->prepare("SELECT id, code, title, from_user_id, to_user_id FROM tickets WHERE code IN ({$marks})");
        $st->execute($codes);
        $out = [];
        foreach ($st->fetchAll() as $t) {
            $out[$t['code']] = [
                'id' => (int) $t['id'],
                'title' => (string) $t['title'],
                'visible' => $viewerIsAdmin || Ticket::involves($t, $viewerId),
            ];
        }
        return $out;
    }

    /** Escaped HTML with ticket mentions turned into buttons that open the ticket. */
    public static function renderBody(string $body, array $refs): string
    {
        $html = preg_replace_callback(self::CODE_PATTERN, static function (array $m) use ($refs): string {
            $ref = $refs[$m[1]] ?? null;
            if (!$ref || !$ref['visible']) {
                return '<span class="chat-ref is-locked" title="ไม่มีสิทธิ์ดูตั๋วงานนี้">#' . View::e($m[1]) . '</span>';
            }
            return '<button type="button" class="chat-ref" data-open-ticket="' . $ref['id'] . '">'
                . '<i class="bi bi-ticket-perforated"></i> #' . View::e($m[1])
                . '<span class="chat-ref-title">' . View::e($ref['title']) . '</span></button>';
        }, View::e($body));
        return nl2br((string) $html, false);
    }

    /**
     * Tickets the viewer may mention, newest first, matching code or title.
     *
     * @return array<int,array{id:int,code:string,title:string,status:string}>
     */
    public static function searchTickets(int $viewerId, bool $viewerIsAdmin, string $q, int $limit = 8): array
    {
        $where = $viewerIsAdmin ? '1=1' : '(t.from_user_id = ? OR t.to_user_id = ?)';
        $params = $viewerIsAdmin ? [] : [$viewerId, $viewerId];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where .= ' AND (t.code LIKE ? OR t.title LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $st = Database::pdo()->prepare(
            "SELECT t.id, t.code, t.title, t.status FROM tickets t WHERE {$where}
             ORDER BY t.created_at DESC, t.id DESC LIMIT " . max(1, min(20, $limit))
        );
        $st->execute($params);
        return array_map(static fn ($t) => [
            'id' => (int) $t['id'],
            'code' => $t['code'],
            'title' => $t['title'],
            'status' => Ticket::STATUS[$t['status']][0] ?? $t['status'],
        ], $st->fetchAll());
    }

    /** "10:15" today, otherwise "24/11 10:15", or with the year when it differs. */
    public static function timeLabel(string $at): string
    {
        $ts = strtotime($at);
        if (date('Y-m-d', $ts) === date('Y-m-d')) {
            return date('H:i', $ts);
        }
        return date(date('Y', $ts) === date('Y') ? 'd/m H:i' : 'd/m/Y H:i', $ts);
    }
}

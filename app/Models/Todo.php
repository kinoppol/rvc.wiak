<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;

final class Todo
{
    public const RECUR_TYPES = [
        'none'         => 'ไม่ซ้ำ',
        'daily'        => 'ทุกวัน',
        'weekly'       => 'ทุกสัปดาห์',
        'monthly_date' => 'ทุกวันที่ของเดือน',
        'yearly'       => 'ทุกปี',
        'interval'     => 'ทุก ๆ N วัน',
    ];

    public const WEEK_DAYS = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

    // ------------------------------------------------------------------ read

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM todos WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** All active (and recently completed) todos for one user, ordered for display. */
    public static function forUser(int $userId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM todo_logs l WHERE l.todo_id = t.id AND l.status = 'missed') AS missed_count
             FROM todos t
             WHERE t.user_id = ?
               AND (t.is_done = 0 OR t.done_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
             ORDER BY
               t.is_done ASC,
               CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END ASC,
               t.due_at ASC,
               t.created_at DESC"
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** Recent log entries for a single todo (for the edit overlay). */
    public static function recentLogs(int $todoId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $st = Database::pdo()->prepare(
            "SELECT * FROM todo_logs WHERE todo_id = ? ORDER BY acted_at DESC LIMIT {$limit}"
        );
        $st->execute([$todoId]);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------ write

    public static function create(int $userId, array $data): int
    {
        [$recurType, $weeklyDays, $recurInterval, $recurDay, $recurMonth] = self::parseRecur($data);

        $st = Database::pdo()->prepare(
            'INSERT INTO todos (user_id, title, note, due_at, recur_type, recur_weekly_days,
                                recur_interval, recur_day, recur_month, recur_end_at, overdue_action)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $userId,
            trim((string) ($data['title'] ?? '')),
            ($data['note'] ?? '') ?: null,
            ($data['due_at'] ?? '') ?: null,
            $recurType,
            $weeklyDays,
            $recurInterval,
            $recurDay,
            $recurMonth,
            ($data['recur_end_at'] ?? '') ?: null,
            ($data['overdue_action'] ?? 'alert') === 'miss' ? 'miss' : 'alert',
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        [$recurType, $weeklyDays, $recurInterval, $recurDay, $recurMonth] = self::parseRecur($data);

        Database::pdo()->prepare(
            'UPDATE todos SET title=?, note=?, due_at=?, recur_type=?, recur_weekly_days=?,
             recur_interval=?, recur_day=?, recur_month=?, recur_end_at=?, overdue_action=?
             WHERE id=?'
        )->execute([
            trim((string) ($data['title'] ?? '')),
            ($data['note'] ?? '') ?: null,
            ($data['due_at'] ?? '') ?: null,
            $recurType,
            $weeklyDays,
            $recurInterval,
            $recurDay,
            $recurMonth,
            ($data['recur_end_at'] ?? '') ?: null,
            ($data['overdue_action'] ?? 'alert') === 'miss' ? 'miss' : 'alert',
            $id,
        ]);
    }

    /** Mark the current occurrence done; advance due_at for recurring items. */
    public static function markDone(int $id, int $userId): bool
    {
        $todo = self::find($id);
        if (!$todo || (int) $todo['user_id'] !== $userId) {
            return false;
        }
        self::logOccurrence($id, $todo['due_at'], 'done');
        self::advanceOrClose($todo);
        return true;
    }

    /** Mark the current occurrence missed; advance due_at for recurring items. */
    public static function markMiss(int $id, int $userId): bool
    {
        $todo = self::find($id);
        if (!$todo || (int) $todo['user_id'] !== $userId) {
            return false;
        }
        self::logOccurrence($id, $todo['due_at'], 'missed');
        self::advanceOrClose($todo);
        return true;
    }

    public static function delete(int $id, int $userId): bool
    {
        $st = Database::pdo()->prepare('DELETE FROM todos WHERE id = ? AND user_id = ?');
        $st->execute([$id, $userId]);
        return $st->rowCount() > 0;
    }

    /**
     * Lazily advance recurring todos with overdue_action='miss' that are past due.
     * Also auto-closes overdue non-recurring todos with overdue_action='miss'.
     * Called on page load; safe to call multiple times.
     */
    public static function advanceMissed(int $userId): void
    {
        $st = Database::pdo()->prepare(
            "SELECT * FROM todos
             WHERE user_id = ? AND is_done = 0 AND overdue_action = 'miss'
               AND due_at IS NOT NULL AND due_at < NOW()"
        );
        $st->execute([$userId]);

        $now = new DateTimeImmutable();
        foreach ($st->fetchAll() as $todo) {
            $safetyLimit = 500;
            while ($safetyLimit-- > 0 && $todo['due_at'] && strtotime($todo['due_at']) < $now->getTimestamp()) {
                self::logOccurrence((int) $todo['id'], $todo['due_at'], 'missed');
                $next = $todo['recur_type'] !== 'none' ? self::nextOccurrence($todo) : null;
                if ($next === null) {
                    Database::pdo()->prepare('UPDATE todos SET is_done=1, done_at=NOW() WHERE id=?')
                        ->execute([$todo['id']]);
                    $todo['due_at'] = null;
                } else {
                    $nStr = $next->format('Y-m-d H:i:s');
                    Database::pdo()->prepare('UPDATE todos SET due_at=? WHERE id=?')
                        ->execute([$nStr, $todo['id']]);
                    $todo['due_at'] = $nStr;
                }
            }
        }
    }

    // ------------------------------------------------------------------ recurrence

    /**
     * Compute the next occurrence after the current due_at.
     * Returns null when recurrence has ended or is not configured.
     */
    public static function nextOccurrence(array $todo): ?DateTimeImmutable
    {
        if ($todo['recur_type'] === 'none' || !$todo['due_at']) {
            return null;
        }

        $current = new DateTimeImmutable($todo['due_at']);

        $next = match ($todo['recur_type']) {
            'daily'        => $current->modify('+1 day'),
            'interval'     => $current->modify('+' . max(1, (int) $todo['recur_interval']) . ' days'),
            'weekly'       => self::nextWeeklyDate($current, (int) $todo['recur_weekly_days']),
            'monthly_date' => self::nextMonthlyDate($current, max(1, min(31, (int) $todo['recur_day']))),
            'yearly'       => $current->modify('+1 year'),
            default        => null,
        };

        if ($next === null) {
            return null;
        }

        if ($todo['recur_end_at']) {
            $end = new DateTimeImmutable($todo['recur_end_at'] . ' 23:59:59');
            if ($next > $end) {
                return null;
            }
        }

        return $next;
    }

    /** Human-readable label for a todo's recurrence pattern. */
    public static function recurLabel(array $todo): string
    {
        return match ($todo['recur_type']) {
            'daily'        => 'ทุกวัน',
            'weekly'       => self::weeklyLabel((int) $todo['recur_weekly_days']),
            'monthly_date' => 'ทุกวันที่ ' . (int) $todo['recur_day'] . ' ของเดือน',
            'yearly'       => 'ทุกปี',
            'interval'     => 'ทุก ' . (int) $todo['recur_interval'] . ' วัน',
            default        => '',
        };
    }

    // ------------------------------------------------------------------ private

    private static function nextWeeklyDate(DateTimeImmutable $from, int $days): ?DateTimeImmutable
    {
        if ($days === 0) {
            return null;
        }
        $next = $from->modify('+1 day');
        for ($i = 0; $i < 7; $i++) {
            if ($days & (1 << (int) $next->format('w'))) {
                break;
            }
            $next = $next->modify('+1 day');
        }
        return $next;
    }

    private static function nextMonthlyDate(DateTimeImmutable $from, int $day): DateTimeImmutable
    {
        $next = $from->modify('+1 month');
        $maxDay = (int) $next->format('t');
        $actualDay = min($day, $maxDay);
        return new DateTimeImmutable(sprintf(
            '%04d-%02d-%02d %s',
            (int) $next->format('Y'),
            (int) $next->format('n'),
            $actualDay,
            $from->format('H:i:s')
        ));
    }

    private static function logOccurrence(int $todoId, ?string $dueAt, string $status): void
    {
        Database::pdo()->prepare(
            'INSERT INTO todo_logs (todo_id, due_at, status) VALUES (?,?,?)'
        )->execute([$todoId, $dueAt, $status]);
    }

    private static function advanceOrClose(array $todo): void
    {
        if ($todo['recur_type'] === 'none') {
            Database::pdo()->prepare('UPDATE todos SET is_done=1, done_at=NOW() WHERE id=?')
                ->execute([$todo['id']]);
            return;
        }
        $next = self::nextOccurrence($todo);
        if ($next === null) {
            Database::pdo()->prepare('UPDATE todos SET is_done=1, done_at=NOW() WHERE id=?')
                ->execute([$todo['id']]);
        } else {
            Database::pdo()->prepare('UPDATE todos SET due_at=? WHERE id=?')
                ->execute([$next->format('Y-m-d H:i:s'), $todo['id']]);
        }
    }

    /** Validate and normalise recurrence fields from form input. Returns [type, weeklyDays, interval, day, month]. */
    private static function parseRecur(array $data): array
    {
        $type = in_array($data['recur_type'] ?? '', array_keys(self::RECUR_TYPES), true)
            ? $data['recur_type'] : 'none';

        $weeklyDays = 0;
        $interval   = null;
        $day        = null;
        $month      = null;

        if ($type === 'weekly') {
            foreach ((array) ($data['recur_weekly_days'] ?? []) as $d) {
                $d = (int) $d;
                if ($d >= 0 && $d <= 6) {
                    $weeklyDays |= (1 << $d);
                }
            }
            if ($weeklyDays === 0) {
                $type = 'none';
            }
        } elseif ($type === 'interval') {
            $interval = max(1, (int) ($data['recur_interval'] ?? 1));
        } elseif ($type === 'monthly_date') {
            $day = max(1, min(31, (int) ($data['recur_day'] ?? 1)));
        } elseif ($type === 'yearly') {
            if (!empty($data['due_at'])) {
                $dt    = new DateTimeImmutable($data['due_at']);
                $month = (int) $dt->format('n');
                $day   = (int) $dt->format('j');
            }
        }

        return [$type, $weeklyDays, $interval, $day, $month];
    }

    private static function weeklyLabel(int $bits): string
    {
        $names = [];
        for ($d = 0; $d < 7; $d++) {
            if ($bits & (1 << $d)) {
                $names[] = self::WEEK_DAYS[$d];
            }
        }
        return 'ทุก ' . implode(' ', $names);
    }
}

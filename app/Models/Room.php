<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Meeting rooms and the media equipment (จอภาพ, เครื่องเสียง, …) each one
 * has. Equipment types are a shared list; `meeting_room_equipment` says
 * which room has which, and how many.
 */
final class Room
{
    /** Palette offered when adding a room — each room gets its own calendar colour. */
    public const COLORS = ['#2563eb', '#16a34a', '#db2777', '#ea580c', '#7c3aed', '#0891b2', '#ca8a04', '#dc2626', '#475569'];

    /** Bootstrap-icon names offered for equipment types. */
    public const ICONS = [
        'tv' => 'จอภาพ', 'projector' => 'โปรเจกเตอร์', 'speaker' => 'ลำโพง', 'mic' => 'ไมโครโฟน',
        'pc-display' => 'คอมพิวเตอร์', 'camera-video' => 'กล้อง', 'easel' => 'กระดาน', 'wifi' => 'อินเทอร์เน็ต',
        'plug' => 'ปลั๊กไฟ', 'tools' => 'อื่น ๆ',
    ];

    /**
     * meeting_rooms.inactive_reason (why a room is closed to new requests)
     * arrives by migration 20261126_add_room_graphic_request.sql.
     */
    public static function hasInactiveReason(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = (bool) Database::pdo()->query("SHOW COLUMNS FROM meeting_rooms LIKE 'inactive_reason'")->fetchColumn();
        }
        return $has;
    }

    /** @return array<int,array> rooms keyed by id, each with an `equipment` list */
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM meeting_rooms' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
        $rooms = [];
        foreach (Database::pdo()->query($sql)->fetchAll() as $r) {
            $r['equipment'] = [];
            $rooms[(int) $r['id']] = $r;
        }
        if ($rooms) {
            $rows = Database::pdo()->query(
                'SELECT me.room_id, me.quantity, e.id, e.name, e.icon
                 FROM meeting_room_equipment me
                 JOIN room_equipment e ON e.id = me.equipment_id
                 ORDER BY e.sort_order, e.name'
            )->fetchAll();
            foreach ($rows as $row) {
                $rid = (int) $row['room_id'];
                if (isset($rooms[$rid])) {
                    $rooms[$rid]['equipment'][(int) $row['id']] = $row;
                }
            }
        }
        return $rooms;
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM meeting_rooms WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $room = $st->fetch();
        if (!$room) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT me.quantity, e.id, e.name, e.icon
             FROM meeting_room_equipment me
             JOIN room_equipment e ON e.id = me.equipment_id
             WHERE me.room_id = ?
             ORDER BY e.sort_order, e.name'
        );
        $st->execute([$id]);
        $room['equipment'] = [];
        foreach ($st->fetchAll() as $e) {
            $room['equipment'][(int) $e['id']] = $e;
        }
        return $room;
    }

    /** @param array<int,int> $equipment equipment id => quantity (0 = not in this room) */
    public static function save(?int $id, array $data, array $equipment): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $values = [
                $data['name'],
                $data['location'] ?: null,
                $data['capacity'] ?: null,
                $data['description'] ?: null,
                $data['color'],
                $data['is_active'] ? 1 : 0,
                (int) $data['sort_order'],
            ];
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO meeting_rooms (name, location, capacity, description, color, is_active, sort_order)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute($values);
                $id = (int) $pdo->lastInsertId();
            } else {
                $values[] = $id;
                $pdo->prepare(
                    'UPDATE meeting_rooms SET name=?, location=?, capacity=?, description=?, color=?, is_active=?, sort_order=?
                     WHERE id=?'
                )->execute($values);
            }

            if (self::hasInactiveReason()) {
                $pdo->prepare('UPDATE meeting_rooms SET inactive_reason = ? WHERE id = ?')
                    ->execute([$data['is_active'] ? null : (($data['inactive_reason'] ?? '') ?: null), $id]);
            }

            $pdo->prepare('DELETE FROM meeting_room_equipment WHERE room_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO meeting_room_equipment (room_id, equipment_id, quantity) VALUES (?,?,?)');
            foreach ($equipment as $eid => $qty) {
                if ($qty > 0) {
                    $ins->execute([$id, $eid, $qty]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $st = Database::pdo()->prepare('SELECT id FROM meeting_rooms WHERE name = ? AND id <> ? LIMIT 1');
        $st->execute([$name, $exceptId ?? 0]);
        return (bool) $st->fetchColumn();
    }

    /** Rooms with booking history are deactivated rather than deleted, so that history survives. */
    public static function delete(int $id): bool
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM room_bookings WHERE room_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
        Database::pdo()->prepare('DELETE FROM meeting_rooms WHERE id = ?')->execute([$id]);
        return true;
    }

    // ------------------------------------------------------------------ equipment types

    /** @return array<int,array> keyed by id */
    public static function equipmentTypes(): array
    {
        $out = [];
        foreach (Database::pdo()->query('SELECT * FROM room_equipment ORDER BY sort_order, name')->fetchAll() as $e) {
            $out[(int) $e['id']] = $e;
        }
        return $out;
    }

    public static function saveEquipment(?int $id, string $name, string $icon): void
    {
        if ($id === null) {
            $next = (int) Database::pdo()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM room_equipment')->fetchColumn();
            Database::pdo()->prepare('INSERT INTO room_equipment (name, icon, sort_order) VALUES (?,?,?)')
                ->execute([$name, $icon, $next]);
            return;
        }
        Database::pdo()->prepare('UPDATE room_equipment SET name = ?, icon = ? WHERE id = ?')->execute([$name, $icon, $id]);
    }

    public static function equipmentNameTaken(string $name, ?int $exceptId = null): bool
    {
        $st = Database::pdo()->prepare('SELECT id FROM room_equipment WHERE name = ? AND id <> ? LIMIT 1');
        $st->execute([$name, $exceptId ?? 0]);
        return (bool) $st->fetchColumn();
    }

    /** Refused while any room still lists it or any booking requested it. */
    public static function deleteEquipment(int $id): bool
    {
        $st = Database::pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM meeting_room_equipment WHERE equipment_id = ?)
                  + (SELECT COUNT(*) FROM room_booking_equipment WHERE equipment_id = ?)'
        );
        $st->execute([$id, $id]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
        Database::pdo()->prepare('DELETE FROM room_equipment WHERE id = ?')->execute([$id]);
        return true;
    }
}

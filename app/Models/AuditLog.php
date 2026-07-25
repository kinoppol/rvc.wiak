<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class AuditLog
{
    public static function record(int $actorId, ?int $actingAsId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO audit_log (actor_user_id, acting_as_user_id, action, entity_type, entity_id, details, ip) VALUES (?,?,?,?,?,?,?)'
        );
        $st->execute([$actorId, $actingAsId, $action, $entityType, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    public static function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return Database::pdo()->query(
            "SELECT a.*, u1.full_name AS actor_name, u2.full_name AS acting_as_name
             FROM audit_log a
             JOIN users u1 ON u1.id = a.actor_user_id
             LEFT JOIN users u2 ON u2.id = a.acting_as_user_id
             ORDER BY a.id DESC LIMIT {$limit}"
        )->fetchAll();
    }
}

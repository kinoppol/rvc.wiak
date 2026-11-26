-- People an admin names as meeting-room approvers, on top of the media งาน's
-- หัวหน้างาน/เจ้าหน้าที่ and admins. They may approve bookings for every room.
-- Idempotent (IF NOT EXISTS) so it is safe to re-run.

CREATE TABLE IF NOT EXISTS room_approvers (
    user_id    INT UNSIGNED NOT NULL,
    added_by   INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_ra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ra_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

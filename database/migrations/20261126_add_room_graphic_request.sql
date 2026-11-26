-- A booking may ask the media งาน to make the project sign graphic shown in
-- the room. graphic_request holds what the sign should say (NULL = not asked),
-- room_booking_files the reference files attached to that request.
-- Also: meeting_rooms.inactive_reason says why a room is closed to new
-- requests (its past and current bookings stay visible).
-- Idempotent (IF NOT EXISTS) so it is safe to re-run.

ALTER TABLE meeting_rooms ADD COLUMN IF NOT EXISTS inactive_reason VARCHAR(255) NULL AFTER is_active;

ALTER TABLE room_bookings ADD COLUMN IF NOT EXISTS graphic_request TEXT NULL AFTER details;

CREATE TABLE IF NOT EXISTS room_booking_files (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id  INT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    mime        VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream',
    size        INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rbf_booking (booking_id),
    CONSTRAINT fk_rbf_booking FOREIGN KEY (booking_id) REFERENCES room_bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_rbf_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

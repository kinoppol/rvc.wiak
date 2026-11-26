-- Meeting-room booking: rooms, their media equipment, and booking requests
-- that take effect only once a หัวหน้างาน/เจ้าหน้าที่ of the media งาน approves.
-- Idempotent (IF NOT EXISTS) so it is safe to re-run.

CREATE TABLE IF NOT EXISTS meeting_rooms (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    location    VARCHAR(255) NULL,
    capacity    SMALLINT UNSIGNED NULL,
    description TEXT NULL,
    color       VARCHAR(16) NOT NULL DEFAULT '#2563eb',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_meeting_room_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS room_equipment (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL,
    icon       VARCHAR(40) NOT NULL DEFAULT 'tools',
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_room_equipment_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meeting_room_equipment (
    room_id      INT UNSIGNED NOT NULL,
    equipment_id INT UNSIGNED NOT NULL,
    quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (room_id, equipment_id),
    CONSTRAINT fk_mre_room FOREIGN KEY (room_id) REFERENCES meeting_rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_mre_equipment FOREIGN KEY (equipment_id) REFERENCES room_equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS room_bookings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id       INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    purpose       VARCHAR(255) NOT NULL,
    details       TEXT NULL,
    attendees     SMALLINT UNSIGNED NULL,
    start_date    DATE NOT NULL,
    end_date      DATE NOT NULL,
    start_time    TIME NOT NULL,
    end_time      TIME NOT NULL,
    status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    decided_by    INT UNSIGNED NULL,
    decided_at    DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rb_room_dates (room_id, status, start_date, end_date),
    KEY idx_rb_user (user_id, start_date),
    KEY idx_rb_status (status, start_date),
    CONSTRAINT fk_rb_room FOREIGN KEY (room_id) REFERENCES meeting_rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_rb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rb_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS room_booking_equipment (
    booking_id   INT UNSIGNED NOT NULL,
    equipment_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (booking_id, equipment_id),
    CONSTRAINT fk_rbe_booking FOREIGN KEY (booking_id) REFERENCES room_bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_rbe_equipment FOREIGN KEY (equipment_id) REFERENCES room_equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO room_equipment (name, icon, sort_order) VALUES
('จอภาพ / ทีวี', 'tv', 10),
('โปรเจกเตอร์', 'projector', 20),
('เครื่องเสียง', 'speaker', 30),
('ไมโครโฟน', 'mic', 40),
('คอมพิวเตอร์', 'pc-display', 50),
('ระบบประชุมออนไลน์ (กล้อง/ลำโพง)', 'camera-video', 60)
ON DUPLICATE KEY UPDATE name = name;

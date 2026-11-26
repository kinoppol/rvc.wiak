-- ระบบมอบหมายและติดตามงาน (EduTask Tracking)
-- MariaDB 10.4+ / utf8mb4
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- roles: fixed catalogue of roles a user can hold, ordered by hierarchy
-- level (used to detect "cross-level" assignment: director=1 ... staff=5,
-- admin=0 sits outside the normal chain of command).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(20)  NOT NULL UNIQUE,
    label         VARCHAR(100) NOT NULL,
    short_label   VARCHAR(40)  NOT NULL,
    icon          VARCHAR(40)  NOT NULL,
    chip_bg       VARCHAR(40)  NOT NULL,
    chip_fg       VARCHAR(20)  NOT NULL,
    hierarchy_level TINYINT UNSIGNED NULL COMMENT 'NULL = outside the assignment chain (e.g. admin)',
    is_assigner   TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(150) NOT NULL,
    email         VARCHAR(150) NULL,
    icon          VARCHAR(40)  NOT NULL DEFAULT 'person-fill',
    avatar_path   VARCHAR(255) NULL COMMENT 'relative path under storage/uploads/avatars, downloaded from the RMS people_pic on sync',
    oa_user_id    INT UNSIGNED NULL UNIQUE COMMENT 'Open Authenticator gateway user id (NULL for password-only accounts)',
    department    VARCHAR(150) NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Idempotent upgrade path for databases created before avatar_path existed
-- (schema.sql is re-run as-is on reinstall; CREATE TABLE IF NOT EXISTS above
-- is a no-op against an existing users table).
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255) NULL AFTER icon;
ALTER TABLE users ADD COLUMN IF NOT EXISTS oa_user_id INT UNSIGNED NULL AFTER avatar_path;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_oa_user_id (oa_user_id);
ALTER TABLE users ADD COLUMN IF NOT EXISTS primary_role VARCHAR(30) NULL AFTER department;
ALTER TABLE users ADD COLUMN IF NOT EXISTS national_id CHAR(13) NULL COMMENT 'Thai national ID, the identity key across RMS and RVC-ONE' AFTER oa_user_id;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_national_id (national_id);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- tickets: the core work-assignment record. Status timestamps are stored
-- individually so duration analytics (time-to-open, time-to-acknowledge,
-- time remaining, ...) can be computed rather than hard-coded.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(30)  NOT NULL UNIQUE,
    title         VARCHAR(255) NOT NULL,
    meta          VARCHAR(255) NULL,
    description   TEXT NULL,
    from_user_id  INT UNSIGNED NOT NULL,
    to_user_id    INT UNSIGNED NOT NULL,
    status        ENUM('new','ack','doing','review','submitted','done','forced','cancelled') NOT NULL DEFAULT 'new',
    priority      ENUM('urgent','high','normal') NOT NULL DEFAULT 'normal',
    is_cross      TINYINT(1)   NOT NULL DEFAULT 0,
    due_at        DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at     DATETIME NULL COMMENT 'first time the assignee viewed the ticket',
    ack_at        DATETIME NULL,
    doing_at      DATETIME NULL,
    submitted_at  DATETIME NULL,
    closed_at     DATETIME NULL COMMENT 'done or forced',
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tickets_status (status),
    KEY idx_tickets_to (to_user_id),
    KEY idx_tickets_from (from_user_id),
    CONSTRAINT fk_tickets_from FOREIGN KEY (from_user_id) REFERENCES users(id),
    CONSTRAINT fk_tickets_to FOREIGN KEY (to_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_files (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id     INT UNSIGNED NOT NULL,
    name          VARCHAR(255) NOT NULL,
    is_link       TINYINT(1)   NOT NULL DEFAULT 0,
    url           VARCHAR(500) NULL COMMENT 'used when is_link=1',
    stored_path   VARCHAR(255) NULL COMMENT 'relative path under storage/uploads, used when is_link=0',
    mime          VARCHAR(120) NULL,
    size_bytes    INT UNSIGNED NULL,
    uploaded_by   INT UNSIGNED NOT NULL,
    uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_files_ticket (ticket_id),
    CONSTRAINT fk_files_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_questions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id     INT UNSIGNED NOT NULL,
    no            SMALLINT UNSIGNED NOT NULL,
    text          TEXT NOT NULL,
    by_user_id    INT UNSIGNED NOT NULL,
    at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answer        TEXT NULL,
    answer_by_user_id INT UNSIGNED NULL,
    answer_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_questions_ticket (ticket_id),
    CONSTRAINT fk_questions_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_questions_by FOREIGN KEY (by_user_id) REFERENCES users(id),
    CONSTRAINT fk_questions_answer_by FOREIGN KEY (answer_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_timeline (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id     INT UNSIGNED NOT NULL,
    label         VARCHAR(255) NOT NULL,
    by_user_id    INT UNSIGNED NULL,
    at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_timeline_ticket (ticket_id),
    CONSTRAINT fk_timeline_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_timeline_user FOREIGN KEY (by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- ticket_edits: field-level history of edits to a ticket after creation.
-- notify_user_id = the assignee at edit time, set only if they had already
-- opened the ticket (they need to be told what changed). seen_at = when
-- they acknowledged the edit.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_edits (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id      INT UNSIGNED NOT NULL,
    by_user_id     INT UNSIGNED NOT NULL,
    notify_user_id INT UNSIGNED NULL,
    changes        TEXT NOT NULL COMMENT 'JSON list of {field,label,old,new}',
    at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    seen_at        DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_edits_ticket (ticket_id),
    KEY idx_edits_notify (notify_user_id, seen_at),
    CONSTRAINT fk_edits_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_edits_by FOREIGN KEY (by_user_id) REFERENCES users(id),
    CONSTRAINT fk_edits_notify FOREIGN KEY (notify_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Windows desktop notifier: paired devices, one-time pairing codes, and
-- the per-user notification feed the app polls.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS desktop_devices (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    name          VARCHAR(80) NOT NULL,
    token_hash    CHAR(64) NOT NULL COMMENT 'SHA-256 of the bearer token',
    app_version   VARCHAR(32) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME NULL,
    revoked_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_desktop_token (token_hash),
    KEY idx_desktop_user (user_id, revoked_at),
    CONSTRAINT fk_desktop_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS desktop_pair_codes (
    code_hash    CHAR(64) NOT NULL COMMENT 'SHA-256 of the one-time code',
    user_id      INT UNSIGNED NOT NULL,
    challenge    CHAR(43) NOT NULL COMMENT 'PKCE S256 challenge',
    device_name  VARCHAR(80) NOT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    PRIMARY KEY (code_hash),
    KEY idx_pair_expires (expires_at),
    CONSTRAINT fk_pair_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS desktop_releases (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version       VARCHAR(32) NOT NULL,
    stored_name   VARCHAR(64) NOT NULL,
    extension     VARCHAR(3) NOT NULL,
    size_bytes    BIGINT UNSIGNED NOT NULL,
    sha256        CHAR(64) NOT NULL,
    package_stored VARCHAR(64) NULL,
    package_name  VARCHAR(120) NULL,
    package_size  BIGINT UNSIGNED NULL,
    package_sha1  CHAR(40) NULL,
    package_sha256 CHAR(64) NULL,
    notes         VARCHAR(1000) NULL,
    is_current    TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by   INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_release_version (version),
    KEY idx_release_current (is_current),
    CONSTRAINT fk_release_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    ticket_id   INT UNSIGNED NOT NULL,
    type        VARCHAR(20) NOT NULL,
    dedupe_key  VARCHAR(80) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at     DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification (user_id, dedupe_key),
    KEY idx_notification_feed (user_id, read_at, id),
    KEY idx_notification_ticket (ticket_id),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- audit_log: every state-changing action, including impersonation.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id     INT UNSIGNED NOT NULL COMMENT 'the real, logged-in account',
    acting_as_user_id INT UNSIGNED NULL COMMENT 'set when the action happened while impersonating',
    action            VARCHAR(80)  NOT NULL,
    entity_type       VARCHAR(40)  NULL,
    entity_id         INT UNSIGNED NULL,
    details           VARCHAR(500) NULL,
    ip                VARCHAR(45)  NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_user_id),
    KEY idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
    CONSTRAINT fk_audit_acting_as FOREIGN KEY (acting_as_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- settings: generic key/value app configuration, editable by admins
-- (e.g. the base URL of the external RMS personnel system used for
-- the people-import sync — see App\Services\PeopleSync).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(80) NOT NULL,
    value      TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Organisational structure. A vocational college is organised as:
--   ผู้อำนวยการ
--     └── รองผู้อำนวยการ — one per ฝ่าย (divisions, 4 of them)
--           └── หัวหน้างาน — one or more งาน (works)
--                 └── เจ้าหน้าที่ — one or more งาน
--     └── หัวหน้าแผนก — one or more แผนก (departments)
--           └── ครู — the แผนก they belong to
-- `works.division_id` is optional: an admin may add a งาน before deciding
-- which ฝ่าย owns it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS divisions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS works (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL UNIQUE,
    division_id INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_works_division (division_id),
    CONSTRAINT fk_works_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS departments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL UNIQUE,
    division_id INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_departments_division (division_id),
    CONSTRAINT fk_departments_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- user_role_units: which org unit a user holds a given role *in*.
-- Scoped per (user, role) because one person can legitimately be
-- หัวหน้างาน of one งาน while also being เจ้าหน้าที่ of another.
-- Exactly one of division_id/work_id/department_id is set per row —
-- which one is determined by the role (see Role::ROLE_UNIT_TYPE).
-- Roles outside the org chart (admin, director) have no rows here.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_role_units (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    role_id       TINYINT UNSIGNED NOT NULL,
    division_id   INT UNSIGNED NULL,
    work_id       INT UNSIGNED NULL,
    department_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_role_unit (user_id, role_id, division_id, work_id, department_id),
    CONSTRAINT fk_uru_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uru_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_uru_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE,
    CONSTRAINT fk_uru_work FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE CASCADE,
    CONSTRAINT fk_uru_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user override of the due-date warning window; NULL = use the
-- admin-configured default from `settings`.
ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_warn_days TINYINT UNSIGNED NULL AFTER department;

-- ---------------------------------------------------------------------
-- todos: personal tasks each user creates for themselves.
-- due_at = next scheduled occurrence; for recurring items it is
-- advanced each time the occurrence is completed or marked missed.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS todos (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    title             VARCHAR(255) NOT NULL,
    note              TEXT NULL,
    due_at            DATETIME NULL,
    is_done           TINYINT(1) NOT NULL DEFAULT 0,
    done_at           DATETIME NULL,
    recur_type        ENUM('none','daily','weekly','monthly_date','yearly','interval') NOT NULL DEFAULT 'none',
    recur_weekly_days TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'bitmask: bit0=Sun bit1=Mon … bit6=Sat',
    recur_interval    SMALLINT UNSIGNED NULL     COMMENT 'for interval: every N days',
    recur_day         TINYINT UNSIGNED NULL      COMMENT 'for monthly_date/yearly: 1-31',
    recur_month       TINYINT UNSIGNED NULL      COMMENT 'for yearly: 1-12 (taken from due_at when NULL)',
    recur_end_at      DATE NULL                  COMMENT 'stop recurring after this date (inclusive)',
    overdue_action    ENUM('alert','miss') NOT NULL DEFAULT 'alert',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_todos_user (user_id, is_done, due_at),
    CONSTRAINT fk_todos_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- todo_logs: history of completed / missed occurrences (recurring or one-off).
CREATE TABLE IF NOT EXISTS todo_logs (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    todo_id   INT UNSIGNED NOT NULL,
    due_at    DATETIME NULL,
    status    ENUM('done','missed') NOT NULL,
    acted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tlog_todo (todo_id),
    CONSTRAINT fk_tlog_todo FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Meeting-room booking (RoomController). A booking takes effect only once
-- a หัวหน้างาน/เจ้าหน้าที่ of the media งาน (setting room_media_work_id), a
-- person named in room_approvers, or an admin approves it. Equipment is seeded at the bottom of this file.
-- ---------------------------------------------------------------------
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

-- People an admin names as approvers for every room (on top of the media งาน).
CREATE TABLE IF NOT EXISTS room_approvers (
    user_id    INT UNSIGNED NOT NULL,
    added_by   INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_ra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ra_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- schema_migrations: tracks which files under database/migrations/*.sql
-- have already been applied, via /admin/migrations. Also created lazily
-- by App\Models\Migration on first use, so this is only needed for a
-- fresh install to already have it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(190) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_by INT UNSIGNED NULL,
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Seed reference data: roles
--
-- hierarchy_level drives cross-level ("ข้ามขั้น") detection. The two
-- branches of the org chart run in parallel, so หัวหน้างาน and
-- หัวหน้าแผนก share a level, as do เจ้าหน้าที่ and ครู:
--   1 ผอ. → 2 รองผอ. → 3 หัวหน้างาน  → 4 เจ้าหน้าที่
--                    → 3 หัวหน้าแผนก → 4 ครู
-- Re-running this file re-syncs every descriptive column, so role
-- metadata is upgraded in place on reinstall.
-- ---------------------------------------------------------------------
INSERT INTO roles (code, label, short_label, icon, chip_bg, chip_fg, hierarchy_level, is_assigner) VALUES
('admin',      'ผู้ดูแลระบบ (Admin)',            'Admin',        'shield-lock-fill',  'rgba(220,38,38,.18)',  '#fca5a5', NULL, 1),
('director',   'ผู้อำนวยการ (Director)',         'ผอ.',          'award-fill',        'rgba(37,99,235,.22)',  '#93c5fd', 1,    1),
('deputy',     'รองผู้อำนวยการ (Deputy)',        'รองผอ.',       'person-vcard-fill', 'rgba(14,165,233,.2)',  '#7dd3fc', 2,    1),
('dept',       'หัวหน้าแผนก (Dept. Head)',       'หัวหน้าแผนก',  'buildings-fill',    'rgba(168,85,247,.2)',  '#d8b4fe', 3,    1),
('supervisor', 'หัวหน้างาน (Supervisor)',        'หัวหน้างาน',   'person-gear',       'rgba(16,185,129,.2)',  '#6ee7b7', 3,    0),
('staff',      'เจ้าหน้าที่ในงาน (Staff)',       'เจ้าหน้าที่',  'person-workspace',  'rgba(148,163,184,.22)','#cbd5e1', 4,    0),
('teacher',    'ครู (Teacher)',                  'ครู',          'mortarboard-fill',  'rgba(234,179,8,.2)',   '#fde047', 4,    0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label), short_label = VALUES(short_label), icon = VALUES(icon),
    chip_bg = VALUES(chip_bg), chip_fg = VALUES(chip_fg),
    hierarchy_level = VALUES(hierarchy_level), is_assigner = VALUES(is_assigner);

-- ---------------------------------------------------------------------
-- Seed reference data: the 4 ฝ่าย. งาน and แผนก are left for the admin
-- to enter at /admin/org, since they vary between colleges.
-- ---------------------------------------------------------------------
INSERT INTO divisions (name) VALUES
('ฝ่ายบริหารทรัพยากร'),
('ฝ่ายแผนงานและความร่วมมือ'),
('ฝ่ายพัฒนากิจการนักเรียนนักศึกษา'),
('ฝ่ายวิชาการ')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------
-- Seed reference data: settings
-- ---------------------------------------------------------------------
-- `key` = `key` is a deliberate no-op: these are defaults for a fresh
-- install and must not clobber values an admin has since changed.
INSERT INTO settings (`key`, value) VALUES
('external_api_base_url', 'http://rms.rvc.ac.th'),
('notify_warn_days_default', '3'),
('notify_urgent_hours', '24')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- ---------------------------------------------------------------------
-- Seed reference data: meeting-room equipment types
-- ---------------------------------------------------------------------
INSERT INTO room_equipment (name, icon, sort_order) VALUES
('จอภาพ / ทีวี', 'tv', 10),
('โปรเจกเตอร์', 'projector', 20),
('เครื่องเสียง', 'speaker', 30),
('ไมโครโฟน', 'mic', 40),
('คอมพิวเตอร์', 'pc-display', 50),
('ระบบประชุมออนไลน์ (กล้อง/ลำโพง)', 'camera-video', 60)
ON DUPLICATE KEY UPDATE name = name;

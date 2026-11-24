-- Windows desktop notifier: paired devices, one-time pairing codes, and the
-- per-user notification feed the app polls.

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

-- Adds the personal To-Do list feature's tables.
-- Idempotent (IF NOT EXISTS) so it is safe to re-run.

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

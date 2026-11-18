-- Editing a ticket after it was created. Each save stores which fields
-- changed (old and new values as JSON) so the assignee can be shown exactly
-- what was edited. notify_user_id is the assignee at edit time, set only when
-- they had already opened the ticket. seen_at is when they acknowledged it.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS ticket_edits (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id      INT UNSIGNED NOT NULL,
    by_user_id     INT UNSIGNED NOT NULL,
    notify_user_id INT UNSIGNED NULL,
    changes        TEXT NOT NULL,
    at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    seen_at        DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_edits_ticket (ticket_id),
    KEY idx_edits_notify (notify_user_id, seen_at),
    CONSTRAINT fk_edits_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_edits_by FOREIGN KEY (by_user_id) REFERENCES users(id),
    CONSTRAINT fk_edits_notify FOREIGN KEY (notify_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

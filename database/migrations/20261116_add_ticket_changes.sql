-- ticket_changes: one row per edit an assigner makes to an already-assigned
-- ticket, holding a JSON list of {field, label, old, new}. notify_user_id is
-- set when the assignee had already opened the ticket, so they are shown what
-- changed until they acknowledge it (seen_at).
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS ticket_changes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id       INT UNSIGNED NOT NULL,
    changed_by      INT UNSIGNED NOT NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    changes         TEXT NOT NULL,
    notify_user_id  INT UNSIGNED NULL,
    seen_at         DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_changes_ticket (ticket_id),
    KEY idx_changes_notify (notify_user_id, seen_at),
    CONSTRAINT fk_changes_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_changes_by FOREIGN KEY (changed_by) REFERENCES users(id),
    CONSTRAINT fk_changes_notify FOREIGN KEY (notify_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

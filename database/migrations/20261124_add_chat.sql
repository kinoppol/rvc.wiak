-- Direct messages between users. pair_key ("smallerId:largerId") keeps one
-- conversation per pair. last_read_id marks what each member has seen.

CREATE TABLE IF NOT EXISTS chat_conversations (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pair_key         VARCHAR(32) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_id  BIGINT UNSIGNED NULL,
    last_message_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_pair (pair_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_members (
    conversation_id  INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    last_read_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_chat_member_user (user_id),
    CONSTRAINT fk_chat_member_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id  INT UNSIGNED NOT NULL,
    sender_id        INT UNSIGNED NOT NULL,
    body             TEXT NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chat_message_conv (conversation_id, id),
    KEY idx_chat_message_sender (sender_id),
    CONSTRAINT fk_chat_message_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_message_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

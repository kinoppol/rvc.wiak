-- One-off correction for timestamps the database wrote in its own time zone
-- (UTC in production) before connections were set to Bangkok time.
--
-- Only columns filled by NOW() or DEFAULT CURRENT_TIMESTAMP are moved.
-- tickets.due_at, todos.due_at, todo_logs.due_at and todos.recur_end_at come
-- from PHP, which was already on Bangkok time, so they are left alone.
--
-- The shift is the gap between the session (+07:00) and the server's own zone,
-- so it is 7 hours on a UTC server and 0 on a server already on Bangkok time.
-- Values from before 2026-11-24 07:00 are old: the fix was deployed after
-- 10:18 Bangkok time, so old values end before 04:00 and new ones start after 10:18.
-- The settings marker makes a second run a no-op, and the transaction means a
-- failure leaves nothing half-moved.

SET @tz_shift = (
    SELECT IF(COUNT(*) = 0,
              COALESCE(TIMESTAMPDIFF(MINUTE, CONVERT_TZ(NOW(), @@session.time_zone, @@global.time_zone), NOW()), 0),
              0)
    FROM settings WHERE `key` = 'tz_shift_applied'
);
SET @tz_cut = '2026-11-24 07:00:00';

START TRANSACTION;

UPDATE users SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at)
WHERE @tz_shift <> 0;

UPDATE tickets SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at),
    opened_at = IF(opened_at < @tz_cut, opened_at + INTERVAL @tz_shift MINUTE, opened_at),
    ack_at = IF(ack_at < @tz_cut, ack_at + INTERVAL @tz_shift MINUTE, ack_at),
    doing_at = IF(doing_at < @tz_cut, doing_at + INTERVAL @tz_shift MINUTE, doing_at),
    submitted_at = IF(submitted_at < @tz_cut, submitted_at + INTERVAL @tz_shift MINUTE, submitted_at),
    closed_at = IF(closed_at < @tz_cut, closed_at + INTERVAL @tz_shift MINUTE, closed_at),
    updated_at = IF(updated_at < @tz_cut, updated_at + INTERVAL @tz_shift MINUTE, updated_at)
WHERE @tz_shift <> 0;

UPDATE ticket_files SET
    uploaded_at = IF(uploaded_at < @tz_cut, uploaded_at + INTERVAL @tz_shift MINUTE, uploaded_at)
WHERE @tz_shift <> 0;

UPDATE ticket_questions SET
    `at` = IF(`at` < @tz_cut, `at` + INTERVAL @tz_shift MINUTE, `at`),
    answer_at = IF(answer_at < @tz_cut, answer_at + INTERVAL @tz_shift MINUTE, answer_at)
WHERE @tz_shift <> 0;

UPDATE ticket_timeline SET
    `at` = IF(`at` < @tz_cut, `at` + INTERVAL @tz_shift MINUTE, `at`)
WHERE @tz_shift <> 0;

UPDATE ticket_edits SET
    `at` = IF(`at` < @tz_cut, `at` + INTERVAL @tz_shift MINUTE, `at`),
    seen_at = IF(seen_at < @tz_cut, seen_at + INTERVAL @tz_shift MINUTE, seen_at)
WHERE @tz_shift <> 0;

UPDATE desktop_devices SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at),
    last_seen_at = IF(last_seen_at < @tz_cut, last_seen_at + INTERVAL @tz_shift MINUTE, last_seen_at),
    revoked_at = IF(revoked_at < @tz_cut, revoked_at + INTERVAL @tz_shift MINUTE, revoked_at)
WHERE @tz_shift <> 0;

UPDATE desktop_pair_codes SET
    expires_at = IF(expires_at < @tz_cut, expires_at + INTERVAL @tz_shift MINUTE, expires_at),
    used_at = IF(used_at < @tz_cut, used_at + INTERVAL @tz_shift MINUTE, used_at)
WHERE @tz_shift <> 0;

UPDATE desktop_releases SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at)
WHERE @tz_shift <> 0;

UPDATE user_notifications SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at),
    read_at = IF(read_at < @tz_cut, read_at + INTERVAL @tz_shift MINUTE, read_at)
WHERE @tz_shift <> 0;

UPDATE audit_log SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at)
WHERE @tz_shift <> 0;

UPDATE settings SET
    updated_at = IF(updated_at < @tz_cut, updated_at + INTERVAL @tz_shift MINUTE, updated_at)
WHERE @tz_shift <> 0;

UPDATE divisions SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at)
WHERE @tz_shift <> 0;

UPDATE works SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at)
WHERE @tz_shift <> 0;

UPDATE departments SET
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at)
WHERE @tz_shift <> 0;

UPDATE todos SET
    done_at = IF(done_at < @tz_cut, done_at + INTERVAL @tz_shift MINUTE, done_at),
    created_at = IF(created_at < @tz_cut, created_at + INTERVAL @tz_shift MINUTE, created_at),
    updated_at = IF(updated_at < @tz_cut, updated_at + INTERVAL @tz_shift MINUTE, updated_at)
WHERE @tz_shift <> 0;

UPDATE todo_logs SET
    acted_at = IF(acted_at < @tz_cut, acted_at + INTERVAL @tz_shift MINUTE, acted_at)
WHERE @tz_shift <> 0;

UPDATE schema_migrations SET
    applied_at = IF(applied_at < @tz_cut, applied_at + INTERVAL @tz_shift MINUTE, applied_at)
WHERE @tz_shift <> 0;

INSERT INTO settings (`key`, value) VALUES ('tz_shift_applied', CONCAT(@tz_shift, ' minutes'))
ON DUPLICATE KEY UPDATE value = value;

COMMIT;

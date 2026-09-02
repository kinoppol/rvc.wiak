-- Open Authenticator (SSO) login: links a local account to a gateway user id.
-- Idempotent (IF NOT EXISTS) so it is safe to re-run.
-- NB: no ';' inside the COMMENT text -- Migration::splitStatements() splits on ';'.

ALTER TABLE users ADD COLUMN IF NOT EXISTS oa_user_id INT UNSIGNED NULL COMMENT 'Open Authenticator gateway user id (NULL for password-only accounts)' AFTER avatar_path;

ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_oa_user_id (oa_user_id);

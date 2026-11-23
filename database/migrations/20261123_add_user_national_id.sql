-- National ID becomes the identity key for a person across RMS and RVC-ONE.
-- Accounts created by the RMS people-sync already use it as their username.
ALTER TABLE users ADD COLUMN IF NOT EXISTS national_id CHAR(13) NULL COMMENT 'Thai national ID, the identity key across RMS and RVC-ONE' AFTER oa_user_id;

ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_national_id (national_id);

UPDATE users SET national_id = username WHERE national_id IS NULL AND username REGEXP '^[0-9]{13}$';

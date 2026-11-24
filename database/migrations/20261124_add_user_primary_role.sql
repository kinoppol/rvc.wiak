-- The role a user with several roles wants selected when they sign in.
ALTER TABLE users ADD COLUMN IF NOT EXISTS primary_role VARCHAR(30) NULL AFTER department;

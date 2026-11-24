-- The Velopack update package stored with each installer release.
ALTER TABLE desktop_releases ADD COLUMN IF NOT EXISTS package_stored VARCHAR(64) NULL AFTER sha256;
ALTER TABLE desktop_releases ADD COLUMN IF NOT EXISTS package_name VARCHAR(120) NULL AFTER package_stored;
ALTER TABLE desktop_releases ADD COLUMN IF NOT EXISTS package_size BIGINT UNSIGNED NULL AFTER package_name;
ALTER TABLE desktop_releases ADD COLUMN IF NOT EXISTS package_sha1 CHAR(40) NULL AFTER package_size;
ALTER TABLE desktop_releases ADD COLUMN IF NOT EXISTS package_sha256 CHAR(64) NULL AFTER package_sha1;

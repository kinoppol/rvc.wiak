-- Link each แผนก to the ฝ่าย it belongs to (like works.division_id), so a
-- deputy's chain of command covers the departments under their division.
-- NULL = not specified. Filled by the RMS org-chart import or on /admin/org.
-- Idempotent: IF NOT EXISTS on every statement.

ALTER TABLE departments ADD COLUMN IF NOT EXISTS division_id INT UNSIGNED NULL AFTER name;
ALTER TABLE departments ADD INDEX IF NOT EXISTS idx_departments_division (division_id);
ALTER TABLE departments ADD CONSTRAINT fk_departments_division FOREIGN KEY IF NOT EXISTS (division_id) REFERENCES divisions(id) ON DELETE SET NULL;

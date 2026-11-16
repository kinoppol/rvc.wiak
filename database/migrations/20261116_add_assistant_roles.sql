-- Adds ผู้ช่วยหัวหน้างาน and ครูช่วยงาน, which sit under หัวหน้างาน and above
-- เจ้าหน้าที่ in the chain of command. They take a new level 4, so เจ้าหน้าที่
-- and ครู move from 4 to 5 (the two branches stay parallel). Level 4 is an
-- optional tier: Role::isCrossLevel() does not count skipping it as cross-level,
-- so หัวหน้างาน → เจ้าหน้าที่ and หัวหน้าแผนก → ครู are still not flagged.
-- Existing tickets keep the is_cross they were stamped with.
-- Idempotent: ON DUPLICATE KEY UPDATE and plain UPDATEs.

INSERT INTO roles (code, label, short_label, icon, chip_bg, chip_fg, hierarchy_level, is_assigner) VALUES
('asst_supervisor', 'ผู้ช่วยหัวหน้างาน (Asst. Supervisor)', 'ผู้ช่วยหัวหน้างาน', 'person-badge',  'rgba(20,184,166,.2)', '#5eead4', 4, 0),
('work_teacher',    'ครูช่วยงาน (Assisting Teacher)',      'ครูช่วยงาน',      'person-video3', 'rgba(249,115,22,.2)', '#fdba74', 4, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label), short_label = VALUES(short_label), icon = VALUES(icon),
    chip_bg = VALUES(chip_bg), chip_fg = VALUES(chip_fg),
    hierarchy_level = VALUES(hierarchy_level), is_assigner = VALUES(is_assigner);

UPDATE roles SET hierarchy_level = 5 WHERE code IN ('staff', 'teacher');

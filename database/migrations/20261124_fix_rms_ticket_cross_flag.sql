-- These two tickets were flagged cross-level only because the assignee also
-- holds the director role. The title check keeps this to the intended rows.
UPDATE tickets SET is_cross = 0
WHERE code IN ('TK-2569-008', 'TK-2569-009') AND title LIKE '%App RMS%';

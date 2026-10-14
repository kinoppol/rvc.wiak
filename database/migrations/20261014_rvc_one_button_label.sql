-- The SSO gateway is now branded RVC-ONE. Installs that saved the SSO settings
-- form stored the old default button caption verbatim -- move those to the new
-- default. A caption an admin actually customised is left alone.
-- Idempotent: re-running matches nothing.

UPDATE settings SET value = 'ลงชื่อเข้าใช้ผ่านระบบ RVC-ONE' WHERE `key` = 'oa_button_label' AND value = 'ลงชื่อเข้าใช้ผ่านระบบ Open Authenticator';

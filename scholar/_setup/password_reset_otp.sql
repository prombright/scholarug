-- ============================================================
-- SCHOLAR — PASSWORD RESET (email OTP)
-- ============================================================
-- Same pattern iClinic already half-built for itself: a 6-digit code +
-- 15-minute expiry stored directly on the users row. Nullable, no
-- backfill needed for existing rows.
--
-- Apply with (replace "scholar" with your actual database name -- on cPanel
-- hosting that's usually yourcpanelusername_scholar, NOT the bare word
-- "scholar"):
--   mysql -u root scholar < password_reset_otp.sql
-- Safe to re-run: guarded ALTERs below.
-- ============================================================

-- No hardcoded USE here on purpose -- this ran against a stray unrelated
-- "scholar" database on live (cPanel names it something like
-- yourcpanelusername_scholar) instead of the real one, while phpMyAdmin
-- reported success because THAT database really was created. Import/run
-- this against whichever database is already selected/specified.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_reset_otp'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN password_reset_otp VARCHAR(10) NULL DEFAULT NULL, ADD COLUMN otp_expires_at DATETIME NULL DEFAULT NULL',
    'SELECT ''users.password_reset_otp already exists, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

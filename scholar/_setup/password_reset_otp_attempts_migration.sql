-- ============================================================
-- SCHOLAR — CAP PASSWORD-RESET OTP GUESSES
-- ============================================================
-- The 6-digit OTP (password_reset_otp.sql) has a 15-minute expiry but no
-- limit on how many codes can be tried against it -- a security review
-- found 1,000,000 possible codes over a 900-second window is within reach
-- of a scripted attacker with no throttling in the way, giving full
-- account takeover (including staff accounts) without knowing the current
-- password.
--
-- Adds a per-user attempt counter, reset to 0 every time a fresh OTP is
-- issued, incremented on every wrong guess, and checked before validating
-- the code -- forgot_password.php rejects further attempts once it hits 5,
-- forcing a fresh code request instead.
--
-- Apply with (replace "scholar" with your actual database name -- on cPanel
-- hosting that's usually yourcpanelusername_scholar, NOT the bare word
-- "scholar"):
--   mysql -u root scholar < password_reset_otp_attempts_migration.sql
-- Safe to re-run: guarded ALTER below.
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_reset_otp_attempts'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN password_reset_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT ''users.password_reset_otp_attempts already exists, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''password_reset_otp_attempts_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

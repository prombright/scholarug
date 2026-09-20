-- ============================================================
-- SCHOLAR — WIDEN schools.access_pin TO HOLD A BCRYPT HASH
-- ============================================================
-- access_pin is VARCHAR(5) NOT NULL -- exactly wide enough for the plain
-- 5-digit PIN it's always stored as, and NOT wide enough for a bcrypt
-- hash (~60 chars). This is why an earlier attempt to hash it broke
-- login in production: whatever wrote a hash into this column either
-- got silently truncated or produced an unexpected value, and
-- password_verify() ended up being called against something that wasn't
-- a plain string, throwing a TypeError on every login attempt.
--
-- This migration ONLY widens the column (VARCHAR(255), matching
-- users.password) -- it does not touch any existing data, so every
-- school's current plain 5-digit PIN keeps working exactly as before
-- until login.php's self-healing rehash (see that file) upgrades it on
-- next successful login. Nothing here can break login on its own.
--
-- Apply with (replace "scholar" with your actual database name -- on
-- cPanel hosting that's usually yourcpanelusername_scholar, NOT the bare
-- word "scholar"):
--   mysql -u root scholar < access_pin_widen_migration.sql
-- Safe to re-run: guarded ALTER below.
-- ============================================================

SET @needs_alter = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools' AND COLUMN_NAME = 'access_pin'
      AND CHARACTER_MAXIMUM_LENGTH < 255
);
SET @sql = IF(@needs_alter > 0,
    'ALTER TABLE schools MODIFY COLUMN access_pin VARCHAR(255) NOT NULL',
    'SELECT ''schools.access_pin is already wide enough, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''access_pin_widen_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- SCHOLAR — ALLOW 'Primary' IN grading_scales.level_type
-- ============================================================
-- grading_scales.level_type was ENUM('O-Level','A-Level') -- inserting
-- 'Primary' into it doesn't error, it silently stores an empty string
-- (MySQL's non-strict-mode ENUM behavior), which is why a Primary
-- school's report card graded every subject "N/A": there was no
-- Primary-appropriate scale UI, and even fixing that UI wouldn't have
-- actually stored anything usable without this.
--
-- Widens the ENUM to add 'Primary' as a third valid value. Existing
-- O-Level/A-Level rows are completely unaffected.
--
-- Apply with (replace "scholar" with your actual database name -- on
-- cPanel hosting that's usually yourcpanelusername_scholar, NOT the bare
-- word "scholar"):
--   mysql -u root scholar < grading_scales_primary_level_migration.sql
-- Safe to re-run: guarded ALTER below.
-- ============================================================

SET @needs_alter = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grading_scales' AND COLUMN_NAME = 'level_type'
      AND COLUMN_TYPE != "enum('O-Level','A-Level','Primary')"
);
SET @sql = IF(@needs_alter > 0,
    "ALTER TABLE grading_scales MODIFY COLUMN level_type ENUM('O-Level','A-Level','Primary') NULL",
    'SELECT ''grading_scales.level_type already allows Primary, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''grading_scales_primary_level_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

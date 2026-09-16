-- ============================================================
-- SCHOLAR — SEPARATE O-LEVEL / A-LEVEL GRADING SCALES
-- ============================================================
-- grading_scales was one shared scale per school, used for both O-Level
-- (percentage bands, e.g. the new competency-based A-Exceptional..E-
-- Elementary curriculum) and A-Level UACE points scoring (calculate_uace_
-- points() in _report_card_render.php, which expects 6/5/4/3/2/1/0-point
-- letter grades with a real F for "fail"). A single school-wide scale
-- can't correctly serve both at once -- the new CBC bands top out at 5
-- points/band (UACE principals need up to 6) and have no F, so every
-- General Paper/Subsidiary attempt would silently count as a pass.
--
-- Adds grading_scales.level_type ('O-Level' | 'A-Level') so a school can
-- keep independent bands per level. Existing rows are backfilled to
-- 'O-Level' (the level almost every school configuring this table today
-- was actually scoring) -- a school with A-Level students needs to set up
-- its own A-Level scale afterward (school_admin/grading_scales.php gets a
-- second "Reset to UACE Standard Scale" seed for exactly this).
--
-- Apply with (replace "scholar" with your actual database name -- on cPanel
-- hosting that's usually yourcpanelusername_scholar, NOT the bare word
-- "scholar"):
--   mysql -u root scholar < grading_scales_level_type_migration.sql
-- Safe to re-run: guarded ALTER + idempotent backfill (only touches rows
-- still NULL).
-- ============================================================

-- No hardcoded USE here on purpose -- this ran against a stray unrelated
-- "scholar" database on live (cPanel names it something like
-- yourcpanelusername_scholar) instead of the real one, while phpMyAdmin
-- reported success because THAT database really was created. Import/run
-- this against whichever database is already selected/specified.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grading_scales' AND COLUMN_NAME = 'level_type'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE grading_scales ADD COLUMN level_type ENUM(''O-Level'',''A-Level'') NULL DEFAULT NULL AFTER school_id',
    'SELECT ''grading_scales.level_type already exists, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE grading_scales SET level_type = 'O-Level' WHERE level_type IS NULL;

-- Self-registers in schema_migrations (see schema_migrations_tracking.sql)
-- so developer/migrations_status.php shows this as applied without a
-- separate manual step -- guarded so running this file BEFORE
-- schema_migrations_tracking.sql still succeeds, it just skips recording.
SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''grading_scales_level_type_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

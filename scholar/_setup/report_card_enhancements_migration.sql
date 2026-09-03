-- ============================================================
-- SCHOLAR — REPORT CARD ENHANCEMENTS
-- ============================================================
-- 1) grading_scales.color -- lets an admin assign a background color to a
--    grade band (min_mark/max_mark), used to color-code report card score
--    cells. Reuses the existing grading band table rather than a new one,
--    since a "color band" IS a score range, which grading_scales already
--    models.
--
-- 2) school_settings gains show_student_photos / allow_student_download /
--    no_data_color. school_settings already existed (school_id UNIQUE,
--    a theme_color column as precedent) but had zero rows and zero
--    references anywhere in the codebase until this change.
--
-- Apply with:
--   mysql -u root scholar < report_card_enhancements_migration.sql
-- Safe to re-run: guarded ALTERs below.
-- ============================================================

USE scholar;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'scholar' AND TABLE_NAME = 'grading_scales' AND COLUMN_NAME = 'color'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE grading_scales ADD COLUMN color VARCHAR(7) NULL DEFAULT NULL AFTER points',
    'SELECT ''grading_scales.color already exists, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'scholar' AND TABLE_NAME = 'school_settings' AND COLUMN_NAME = 'show_student_photos'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE school_settings
        ADD COLUMN show_student_photos TINYINT(1) NOT NULL DEFAULT 1,
        ADD COLUMN allow_student_download TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN no_data_color VARCHAR(7) NULL DEFAULT NULL',
    'SELECT ''school_settings report columns already exist, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- SCHOLAR — PER-PAPER CONTRIBUTION WEIGHTS FOR TWO-PAPER SUBJECTS
-- ============================================================
-- A subject with papers_count = 2 (e.g. Physics/Chemistry/Biology at
-- S.3/S.4) already lets a teacher enter both Paper 1 and Paper 2 marks,
-- and the report card already combines them into one effective mark per
-- assessment -- but only ever as a flat, unweighted 50/50 average
-- (AVG(sm.marks) in _report_card_render.php). There was no way to say
-- "Paper 1 counts for 60%, Paper 2 for 40%" the way a real exam board
-- weighting might require.
--
-- Adds two nullable-by-default-but-always-populated weight columns to
-- `subjects`, defaulting to 50.00/50.00 -- which is mathematically
-- identical to the old plain AVG(), so every existing subject's report
-- card numbers are UNCHANGED the moment this migration runs. An admin
-- only needs to touch subject_matrix.php's Paper 1/Paper 2 weight fields
-- if they actually want something other than an even split.
--
-- Apply with (replace "scholar" with your actual database name -- on
-- cPanel hosting that's usually yourcpanelusername_scholar, NOT the bare
-- word "scholar"):
--   mysql -u root scholar < subject_paper_weights_migration.sql
-- Safe to re-run: guarded ALTER below.
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'paper1_weight_percentage'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE subjects ADD COLUMN paper1_weight_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00, ADD COLUMN paper2_weight_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00',
    'SELECT ''subjects.paper1_weight_percentage already exists, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''subject_paper_weights_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

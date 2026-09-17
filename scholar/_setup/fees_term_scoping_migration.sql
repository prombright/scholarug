-- ============================================================
-- SCHOLAR — SCOPE FEE STRUCTURES AND PAYMENTS BY TERM/YEAR
-- ============================================================
-- fee_structures was one evergreen row per class (no term concept at
-- all), and fee_payments had no term/year either -- the ledger summed
-- EVERY payment a student ever made against whatever the CURRENT fee
-- rate happens to be. Raising tuition for a new term instantly
-- recalculated every student's balance using the new rate against old
-- payments made under the old rate, so an already-settled family from
-- last term could suddenly show a balance due.
--
-- Adds nullable term/year to both tables. Existing rows are backfilled
-- to each school's own current_term/current_year (schools.current_term/
-- current_year), so today's numbers for the CURRENT term stay exactly
-- as they were right after this migration runs -- nothing changes
-- retroactively for anyone. Going forward:
--   - fee_payments.term/year records which term a payment was actually
--     FOR (set by whichever term is selected on the Fees page when it's
--     recorded), and the ledger only sums payments matching the term
--     being viewed.
--   - fee_structures gets a new row per (school_id, class_id, term, year)
--     whenever an admin saves a rate -- building up real history instead
--     of overwriting one evergreen row. The ledger looks up the EXACT
--     term/year match first; if a school never re-saves a rate for a new
--     term, it falls back to that class's most recent prior rate instead
--     of showing nothing, so nothing breaks for a school that doesn't
--     use this feature.
--
-- Apply with (replace "scholar" with your actual database name -- on cPanel
-- hosting that's usually yourcpanelusername_scholar, NOT the bare word
-- "scholar"):
--   mysql -u root scholar < fees_term_scoping_migration.sql
-- Safe to re-run: every ALTER/index change is guarded, and the backfill
-- only ever touches rows still NULL.
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fee_structures' AND COLUMN_NAME = 'term'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE fee_structures ADD COLUMN term VARCHAR(20) NULL AFTER class_id, ADD COLUMN year VARCHAR(10) NULL AFTER term',
    'SELECT ''fee_structures.term/year already exist, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fee_payments' AND COLUMN_NAME = 'term'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE fee_payments ADD COLUMN term VARCHAR(20) NULL AFTER student_id, ADD COLUMN year VARCHAR(10) NULL AFTER term',
    'SELECT ''fee_payments.term/year already exist, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill BEFORE widening the unique key -- these UPDATEs are safe to
-- re-run (WHERE ... IS NULL), and need to run whether or not this is the
-- first time the columns were just added.
UPDATE fee_structures fs
JOIN schools s ON s.id = fs.school_id
SET fs.term = s.current_term, fs.year = s.current_year
WHERE fs.term IS NULL;

UPDATE fee_payments fp
JOIN schools s ON s.id = fp.school_id
SET fp.term = s.current_term, fp.year = s.current_year
WHERE fp.term IS NULL;

-- Widen fee_structures' unique key from (school_id, class_id) to include
-- term/year, so a school can have one rate per class per term instead of
-- exactly one rate per class forever. Guarded on the OLD key still
-- existing (nothing to do if a previous run already widened it).
SET @old_key_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fee_structures'
      AND INDEX_NAME = 'school_class_unique' AND SEQ_IN_INDEX = 1
      AND NOT EXISTS (
          SELECT 1 FROM information_schema.STATISTICS s2
          WHERE s2.TABLE_SCHEMA = DATABASE() AND s2.TABLE_NAME = 'fee_structures'
            AND s2.INDEX_NAME = 'school_class_unique' AND s2.COLUMN_NAME = 'term'
      )
);
SET @sql = IF(@old_key_exists > 0,
    'ALTER TABLE fee_structures DROP INDEX school_class_unique, ADD UNIQUE KEY school_class_term_year_unique (school_id, class_id, term, year)',
    'SELECT ''fee_structures unique key already widened, skipping'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''fees_term_scoping_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

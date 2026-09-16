-- ============================================================
-- SCHOLAR — SCHEMA MIGRATIONS TRACKING
-- ============================================================
-- Nothing in this project ever recorded which _setup/*.sql files had
-- actually been applied to a given database -- "did we run this one on
-- live?" was only answerable by inspecting the live schema by hand. That
-- silence is exactly what let grading_scales_level_type_migration.sql go
-- unapplied and quietly break every report card until someone noticed.
--
-- This table is the fix: one row per applied migration filename. It does
-- NOT try to retroactively guess which of the pre-existing _setup/*.sql
-- files already ran on any given database -- that would risk recording a
-- migration as "applied" on a database where it actually wasn't, which is
-- worse than not tracking it at all. Instead:
--   - New migrations should end with their own
--     INSERT IGNORE INTO schema_migrations (filename) VALUES ('<name>.sql');
--     so running the file is the only step needed -- it registers itself.
--   - developer/_run_pending_migrations.php records each migration it
--     successfully applies here too.
--   - developer/migrations_status.php shows, per migration file, whether
--     it's recorded here yet, with a manual "Mark as Applied" action for
--     confirming older files a developer has verified by other means
--     (exactly the direct-schema-inspection check already done once for
--     every pre-existing _setup/*.sql file on the local database).
--
-- Apply with (replace "scholar" with your actual database name -- on cPanel
-- hosting that's usually yourcpanelusername_scholar, NOT the bare word
-- "scholar"):
--   mysql -u root scholar < schema_migrations_tracking.sql
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- ============================================================

-- No hardcoded USE here on purpose -- this ran against a stray unrelated
-- "scholar" database on live (cPanel names it something like
-- yourcpanelusername_scholar) instead of the real one, while phpMyAdmin
-- reported success because THAT database really was created. Import/run
-- this against whichever database is already selected/specified.

CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO schema_migrations (filename) VALUES ('schema_migrations_tracking.sql');

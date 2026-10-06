-- ============================================================
-- SCHOLAR — SITE/APP VISIT TRACKING (page_views)
-- ============================================================
-- Replaces the old scholar_track_visit() in preloader.php, which wrote
-- into a *separate* `abn_platform` database that was never actually
-- provisioned on this hosting account -- every call silently failed
-- (caught by its own try/catch), so zero visit data was ever collected
-- despite the tracking code existing and running on every page load.
--
-- Self-contained in this same `scholar` database instead: no
-- cross-database dependency, no silent total-failure mode. One row per
-- page view, covering both the Scholar app (system='scholar') and the
-- marketing site (system='site'). school_id/role are only ever
-- populated for an authenticated app view -- always NULL for the public
-- marketing site.
--
-- Apply with (replace "scholar" with your actual database name -- on
-- cPanel hosting that's usually yourcpanelusername_scholar, NOT the bare
-- word "scholar"):
--   mysql -u root scholar < page_views_migration.sql
-- Safe to re-run: guarded CREATE TABLE IF NOT EXISTS below.
-- ============================================================

CREATE TABLE IF NOT EXISTS page_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    system VARCHAR(20) NOT NULL,
    path VARCHAR(255) NOT NULL,
    school_id INT NULL,
    role VARCHAR(30) NULL,
    ip_hash CHAR(64) NULL,
    referrer VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_views_created (created_at),
    INDEX idx_page_views_system (system),
    INDEX idx_page_views_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''page_views_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

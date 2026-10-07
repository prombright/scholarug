-- ============================================================
-- SCHOLAR — LOGIN ATTEMPT IP LOGGING
-- ============================================================
-- login_lockout_migration.sql's login_attempts table was deliberately
-- NOT keyed by IP (a whole school can share one NAT'd IP, so IP was never
-- used for the lockout decision itself -- see that file's header). This
-- adds a separate, logging-only ip_address column so a developer can see
-- WHERE failed attempts came from without that visibility ever feeding
-- back into who gets locked out.
--
-- Apply with (replace "scholar" with your actual database name):
--   mysql -u root scholar < login_attempts_ip_migration.sql
-- Safe to re-run: run_statements()'s caller already treats MySQL error
-- 1060 (duplicate column) as "already applied".
-- ============================================================

ALTER TABLE login_attempts ADD COLUMN ip_address VARCHAR(45) NULL AFTER succeeded;
CREATE INDEX idx_login_attempts_ip ON login_attempts (ip_address);

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''login_attempts_ip_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

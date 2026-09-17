-- ============================================================
-- SCHOLAR — LOGIN ATTEMPT TRACKING (RATE-LIMITING / LOCKOUT)
-- ============================================================
-- Neither login.php's school-code+PIN path nor its normal username/
-- password path had any rate-limiting or lockout at all -- a security
-- review found the school-admin PIN in particular is brute-forceable
-- with no throttling (school codes are a public, guessable "SC-###"
-- format, and the PIN itself was compared in plaintext with no lockout).
--
-- One row per login attempt, keyed by whatever identifier was typed
-- (username, email, or school code) -- not by IP, since a whole school
-- can share one NAT'd IP and blocking by IP would lock out every
-- legitimate user behind it, not just an attacker.
--
-- Deliberately NOT applied to student accounts (see login.php's own
-- comment on this) -- a student's username/password are both just their
-- student number, and real students forget/mistype it often with no
-- self-service reset flow of their own; locking them out would cause
-- more harm than the brute-force risk being mitigated, which is judged
-- lower for student accounts specifically. login.php only calls
-- login_record_attempt()/login_is_locked_out() for the school-code+PIN
-- branch (always school_admin) and for username/password rows whose
-- resolved role isn't 'student'.
--
-- Apply with (replace "scholar" with your actual database name -- on
-- cPanel hosting that's usually yourcpanelusername_scholar, NOT the bare
-- word "scholar"):
--   mysql -u root scholar < login_lockout_migration.sql
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- ============================================================

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(191) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_identifier_time (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''login_lockout_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

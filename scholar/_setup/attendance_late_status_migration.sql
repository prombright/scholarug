-- ============================================================
-- SCHOLAR — ADD 'late' TO attendance.status, FIX VOCABULARY MISMATCH
-- ============================================================
-- school_admin/attendance.php's whole-class attendance dropdown has always
-- offered 4 options (Present/Absent/Late/Excused), but attendance.status is
-- a strict ENUM('present','absent','sick','permission') -- only 2 of those
-- 4 dropdown values have ever actually matched a real enum member. Under a
-- non-strict sql_mode, saving "Late" or "Excused" silently truncates the
-- column to '' with no error, so the page reports "Attendance saved
-- successfully" while the row is actually corrupted. Under STRICT_TRANS_
-- TABLES, the same write throws instead -- and since a whole class is saved
-- in one transaction, ONE student marked Late/Excused rolls back every
-- other student's attendance for that day too.
--
-- Fix: add 'late' as a real 5th value (genuinely distinct from present/
-- absent/sick/permission -- a late student did attend, just not on time,
-- which is worth tracking separately). "Excused" is folded into the
-- existing 'permission' value instead of adding a 6th near-duplicate
-- concept -- an excused absence and a permitted absence are the same
-- thing. scholar/school_admin/attendance.php and _attendance_helpers.php
-- are updated in the same change to use these exact values.
--
-- Apply with (replace "scholar" with your actual database name -- on cPanel
-- hosting that's usually yourcpanelusername_scholar, NOT the bare word
-- "scholar"):
--   mysql -u root scholar < attendance_late_status_migration.sql
-- Safe to re-run: MODIFY COLUMN is idempotent (re-applying the same enum
-- definition is a no-op), and existing 'present'/'absent'/'sick'/
-- 'permission' rows are untouched -- MySQL only adds 'late' as a new
-- allowed value, it doesn't rewrite any existing data.
-- ============================================================

ALTER TABLE attendance
    MODIFY COLUMN status ENUM('present','absent','sick','permission','late') NULL DEFAULT 'present';

SET @tracking_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
);
SET @sql = IF(@tracking_table_exists = 1,
    'INSERT IGNORE INTO schema_migrations (filename) VALUES (''attendance_late_status_migration.sql'')',
    'SELECT ''schema_migrations table not present yet, skipping self-registration'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

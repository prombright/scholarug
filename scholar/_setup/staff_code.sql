-- ============================================================
-- SCHOLAR — STAFF DISPLAY CODE
-- ============================================================
-- staff_manager.php generates a formatted display code (e.g. "STF-2026-0001")
-- and used to write it straight into `staff.staff_id`, the real
-- AUTO_INCREMENT primary key -- MySQL truncated the non-numeric string to 0,
-- and because staff_id is auto-increment, that silently substituted the
-- next real integer instead. This column gives the formatted code
-- somewhere real to live, mirroring how students.student_no / schools.school_code
-- already separate a display code from the real integer PK.
--
-- Nullable + nullable-safe UNIQUE KEY: existing rows just stay NULL (no
-- backfill -- staff_id already displays correctly today for them regardless),
-- only newly-registered staff get a code from here on.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < staff_code.sql
-- Safe to re-run: every ALTER is guarded.
-- ============================================================

USE scholar;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='staff' AND COLUMN_NAME='staff_code');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE staff ADD COLUMN staff_code VARCHAR(20) NULL AFTER staff_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @key_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='staff' AND INDEX_NAME='uniq_staff_code');
SET @sql = IF(@key_exists = 0, 'ALTER TABLE staff ADD UNIQUE KEY uniq_staff_code (staff_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

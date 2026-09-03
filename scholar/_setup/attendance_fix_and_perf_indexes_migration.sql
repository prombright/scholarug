-- ============================================================
-- SCHOLAR — ATTENDANCE SCHEMA FIX + FOLLOW-UP PERFORMANCE INDEXES
-- ============================================================
-- Found during the 2026-08-07 pre-deployment audit.
--
-- 1) school_admin/attendance.php (whole-class daily attendance) has always
--    written/read a `school_id` column on `attendance` that never existed
--    -- every save there threw and rolled back, which is why the table
--    only ever accumulated 2 rows. teacher_attendance.php (the working,
--    actually-used roll call) writes student_id/class_id/subject_id/
--    teacher_id per period and never needed school_id, since subject_id +
--    teacher_id are always populated there.
--
--    Fix: add `school_id` (nullable -- old/teacher-recorded rows leave it
--    NULL) and make subject_id/teacher_id nullable too, since whole-class
--    daily attendance genuinely has no single subject. The two flows now
--    coexist in one table without colliding: teacher roll-call rows are
--    identified by a populated subject_id, admin whole-class rows by a
--    populated school_id (and NULL subject_id) -- enforced by
--    attendance.php's own duplicate-check query, not a DB constraint.
--
-- 2) Composite/covering indexes for the two full-table-scan queries found
--    by the performance audit: fee_payments (hit on every student portal
--    load + the whole-school fee ledger) and attendance (hit on every
--    headteacher dashboard "today" widget).
--
-- Safe to re-run individually; skip any line whose column/index already
-- exists on the target database. Apply with:
--   mysql -u root scholar < attendance_fix_and_perf_indexes_migration.sql
-- ============================================================

USE scholar;

ALTER TABLE attendance
  ADD COLUMN school_id INT NULL AFTER id,
  MODIFY subject_id INT NULL,
  MODIFY teacher_id INT NULL,
  ADD INDEX idx_attendance_school (school_id),
  ADD INDEX idx_attendance_class_date (class_id, attendance_date);

CREATE INDEX idx_fee_payments_school_student ON fee_payments (school_id, student_id);

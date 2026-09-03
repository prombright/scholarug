-- ============================================================
-- SCHOLAR — SCHEDULING DATA INTEGRITY (prep for the upcoming timetable engine)
-- ============================================================
-- teacher_assignments (the table a timetable engine will be built directly
-- on top of) had NO foreign keys on class_id/subject_id at all -- nothing
-- stopped a row pointing at a deleted or cross-school class/subject. streams
-- similarly had no FK to schools, and no link at all to classes.id (only
-- fragile class_name string matching -- the same "S1" vs "S.1" issue
-- already patched defensively in _report_card_render.php, settings.php,
-- subject_enrollment.php, and student_subjects.php).
--
-- This migration deletes confirmed-orphaned rows first (required before a
-- FOREIGN KEY can be added, since MySQL refuses to add a constraint that
-- existing data would violate), then adds the missing FKs. Take a backup
-- first: mysqldump -u root scholar streams teacher_assignments >
-- _setup/backup_before_scheduling_integrity.sql (already done once for this
-- run -- re-run it yourself first if applying this somewhere else).
--
-- Run against the `scholar` database:
--   mysql -u root scholar < scheduling_data_integrity.sql
-- Safe to re-run: every DELETE targets only genuinely orphaned rows (a
-- 0-row no-op once already clean), every ALTER is guarded.
-- ============================================================

USE scholar;

-- ---- 1. teacher_assignments: remove orphans, then add FKs ----
DELETE ta FROM teacher_assignments ta LEFT JOIN classes c ON c.id = ta.class_id WHERE c.id IS NULL;
SELECT ROW_COUNT() AS orphan_teacher_assignments_class_rows_removed;

DELETE ta FROM teacher_assignments ta LEFT JOIN subjects s ON s.id = ta.subject_id WHERE s.id IS NULL;
SELECT ROW_COUNT() AS orphan_teacher_assignments_subject_rows_removed;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='teacher_assignments' AND CONSTRAINT_NAME='fk_ta_class');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE teacher_assignments ADD CONSTRAINT fk_ta_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='teacher_assignments' AND CONSTRAINT_NAME='fk_ta_subject');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE teacher_assignments ADD CONSTRAINT fk_ta_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---- 2. streams: remove rows pointing at schools that no longer exist, then FK ----
DELETE st FROM streams st LEFT JOIN schools sc ON sc.id = st.school_id WHERE sc.id IS NULL;
SELECT ROW_COUNT() AS orphan_streams_school_rows_removed;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='streams' AND CONSTRAINT_NAME='fk_streams_school');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE streams ADD CONSTRAINT fk_streams_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---- 3. streams.class_id: nullable link to classes.id, backfilled ----
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='streams' AND COLUMN_NAME='class_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE streams ADD COLUMN class_id INT NULL AFTER class_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Match on BOTH class_name and stream_name -- classes stores one row per
-- class+stream combination (same shape as streams), so matching class_name
-- alone is ambiguous wherever a school has more than one stream per class
-- (confirmed for 2 real schools in this DB). Explicit COLLATE needed:
-- streams is utf8mb4_general_ci, classes is utf8mb4_unicode_ci -- a raw
-- comparison between them throws "Illegal mix of collations".
UPDATE streams st
JOIN classes c
  ON c.school_id = st.school_id
 AND REPLACE(UPPER(c.class_name), '.', '') = REPLACE(UPPER(st.class_name COLLATE utf8mb4_unicode_ci), '.', '')
 AND REPLACE(UPPER(c.stream_name), '.', '') = REPLACE(UPPER(st.stream_name COLLATE utf8mb4_unicode_ci), '.', '')
SET st.class_id = c.id
WHERE st.class_id IS NULL;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='streams' AND CONSTRAINT_NAME='fk_streams_class');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE streams ADD CONSTRAINT fk_streams_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

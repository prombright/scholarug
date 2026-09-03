-- ============================================================
-- iLEARNING — TEXT MARKING (highlight + inline comment)
-- ============================================================
-- A teacher grading a student's free-text submission can select a
-- portion of the text and mark it (correct/incorrect/note) with an
-- optional short comment, instead of only reading a plain text dump and
-- typing one overall score. One row per marked range.
--
-- source_type/source_id point at whichever of the two existing
-- submission tables the marked text actually lives in -- there was no
-- single "submissions" table to attach a normal FK to:
--   'pdf_submission' -> ilearning_pdf_submissions.id (topic_roster.php)
--   'open_answer'    -> ilearning_attempt_answers.id (grade_open_answers.php)
-- Both already store the submitted text as one plain-text column, so a
-- character offset pair is stable per row.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < ilearning_annotations_migration.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS ilearning_text_annotations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    source_type ENUM('pdf_submission','open_answer') NOT NULL,
    source_id INT NOT NULL,
    start_offset INT UNSIGNED NOT NULL,
    end_offset INT UNSIGNED NOT NULL,
    mark_type ENUM('correct','incorrect','note') NOT NULL DEFAULT 'note',
    comment VARCHAR(500) NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_annot_source (source_type, source_id),
    CONSTRAINT fk_annot_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_annot_teacher FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
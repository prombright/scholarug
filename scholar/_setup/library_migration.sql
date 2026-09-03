-- ============================================================
-- LIBRARY — notes + past papers (replaces iLearning's teaching-content
-- role; the quiz/assessment/live-class/billing side of iLearning is left
-- untouched in place, just no longer linked from the nav)
-- ============================================================
-- One table, deliberately not reusing ilearning_topics/ilearning_attachments
-- -- those carry columns (assessment_id, target_days_to_complete, the whole
-- content_type split) that only make sense for the quiz/grading pathway
-- this feature has nothing to do with. A document here is either a
-- teacher's reading note or a past exam paper, always a single PDF, always
-- view-only -- library_serve_pdf.php never honors download=1 for any row
-- in this table, unlike serve_pdf.php's per-content_type carve-out.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < library_migration.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS library_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    category ENUM('notes','past_paper') NOT NULL,
    title VARCHAR(255) NOT NULL,
    term VARCHAR(20) NULL,
    year INT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    status ENUM('Draft','Published') NOT NULL DEFAULT 'Published',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_library_school_class_subject (school_id, class_id, subject_id),
    KEY idx_library_teacher (teacher_id),
    KEY idx_library_category (category),
    CONSTRAINT fk_library_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_library_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_library_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_library_teacher FOREIGN KEY (teacher_id) REFERENCES staff(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

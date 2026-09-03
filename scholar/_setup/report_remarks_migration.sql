-- ============================================================
-- SCHOLAR — REPORT CARD REMARKS
-- ============================================================
-- Free-text Class Teacher's Remark / Head Teacher's Remark per
-- student per term, shown on the report card alongside the
-- already-computed grade/average summary. One row per
-- (school, student, term, year); the two remark columns are
-- written by different roles (see school_admin/remarks.php),
-- so both live on the same row instead of two separate tables.
--
-- Apply with: mysql -u root scholar < report_remarks_migration.sql
-- ============================================================

USE scholar;

CREATE TABLE IF NOT EXISTS report_card_remarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    term VARCHAR(50) NOT NULL,
    year INT NOT NULL,
    class_teacher_remark TEXT NULL,
    head_teacher_remark TEXT NULL,
    class_teacher_updated_by INT NULL,
    head_teacher_updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_remark (school_id, student_id, term, year),
    KEY idx_school_term (school_id, term, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
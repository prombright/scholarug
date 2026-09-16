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

-- No hardcoded USE here on purpose -- this ran against a stray unrelated
-- "scholar" database on live (cPanel names it something like
-- yourcpanelusername_scholar) instead of the real one, while phpMyAdmin
-- reported success because THAT database really was created. Import/run
-- this against whichever database is already selected/specified.

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
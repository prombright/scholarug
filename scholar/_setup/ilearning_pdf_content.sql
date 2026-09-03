-- ============================================================
-- SCHOLAR — iLEARNING: PDF NOTES & PDF ACTIVITIES
-- ============================================================
-- Adds two new topic content types alongside the existing plain-text
-- "written" topic:
--   - pdf_notes:    student can view AND download the PDF, no response
--                   required. Reading progress is tracked the same way a
--                   written topic's is (ilearning_progress, driven by
--                   progress_ping.php) -- the only difference is the
--                   percent is computed from real page-visibility inside a
--                   pdf.js-rendered viewer instead of document scrollY,
--                   since a browser's native PDF viewer doesn't expose
--                   scroll position to JavaScript. progress_ping.php itself
--                   needs zero changes -- it never cared how percent was
--                   computed, only that it's a 0-100 value per topic_id.
--   - pdf_activity: same PDF, but served without a download option (view
--                   inline only, see ilearning/serve_pdf.php) and rendered
--                   canvas-only (no text layer), paired with a free-text
--                   answer box. Answers land in the new
--                   ilearning_pdf_submissions table below, completely
--                   separate from ilearning_progress (reading the PDF and
--                   submitting an answer are two distinct, honestly
--                   tracked signals, not conflated into one).
--
-- Run against the `scholar` database:
--   mysql -u root scholar < ilearning_pdf_content.sql
-- Safe to re-run: every ALTER is guarded, CREATE TABLE uses IF NOT EXISTS.
-- ============================================================

USE scholar;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='ilearning_topics' AND COLUMN_NAME='content_type');
SET @sql = IF(@col_exists = 0, "ALTER TABLE ilearning_topics ADD COLUMN content_type ENUM('written','pdf_notes','pdf_activity') NOT NULL DEFAULT 'written'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='ilearning_topics' AND COLUMN_NAME='primary_pdf_attachment_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ilearning_topics ADD COLUMN primary_pdf_attachment_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='ilearning_topics' AND CONSTRAINT_NAME='fk_topic_primary_pdf');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE ilearning_topics ADD CONSTRAINT fk_topic_primary_pdf FOREIGN KEY (primary_pdf_attachment_id) REFERENCES ilearning_attachments(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 'public' = today's behavior unchanged (scholar/uploads/ilearning/, direct
-- link, downloadable) -- every existing attachment defaults here so nothing
-- about the current generic multi-attachment feature on written topics
-- changes. 'private' = scholar/uploads/ilearning_private/ (denied to direct
-- HTTP access via .htaccess), only ever readable through serve_pdf.php's
-- auth-gated stream -- used for both pdf_notes and pdf_activity PDFs, so
-- there's exactly one gate to reason about regardless of whether download
-- ends up allowed (pdf_notes) or blocked (pdf_activity).
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='scholar' AND TABLE_NAME='ilearning_attachments' AND COLUMN_NAME='storage');
SET @sql = IF(@col_exists = 0, "ALTER TABLE ilearning_attachments ADD COLUMN storage ENUM('public','private') NOT NULL DEFAULT 'public'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ilearning_pdf_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    topic_id INT NOT NULL,
    student_id INT NOT NULL,
    answer_text TEXT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_topic_student (topic_id, student_id),
    CONSTRAINT fk_pdfsub_topic FOREIGN KEY (topic_id) REFERENCES ilearning_topics(id) ON DELETE CASCADE,
    CONSTRAINT fk_pdfsub_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

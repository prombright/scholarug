-- ============================================================
-- iLEARNING — CORE CONTENT (topics, questions, attempts, progress)
-- ============================================================
-- Teacher-authored fields (teacher_id/created_by/graded_by) are INT,
-- matching staff.staff_id's real column type (verified live: staff_id is
-- an AUTO_INCREMENT INT primary key, NOT a VARCHAR business key) --
-- same FK target every existing teacher_assignments/student_marks row
-- already uses.
--
-- ilearning_topics.assessment_id points at the EXISTING, admin-owned
-- `assessments` table (school_admin/assessments.php) -- not a new
-- iLearning-specific concept -- so generate_report.php needs zero
-- changes to pick up iLearning-sourced marks. NULL means practice-only,
-- structurally incapable of ever reaching a report card.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < ilearning_core_migration.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS ilearning_topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    term VARCHAR(20) NOT NULL,
    year INT NOT NULL,
    target_days_to_complete INT NULL,
    assessment_id INT NULL,
    status ENUM('Draft','Published','Archived') NOT NULL DEFAULT 'Draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_topics_school_class_subject (school_id, class_id, subject_id),
    KEY idx_topics_teacher (teacher_id),
    CONSTRAINT fk_topic_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_topic_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_topic_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_topic_teacher FOREIGN KEY (teacher_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
    CONSTRAINT fk_topic_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ilearning_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_ext VARCHAR(10) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attach_topic FOREIGN KEY (topic_id) REFERENCES ilearning_topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ilearning_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    topic_id INT NULL,
    pool_type ENUM('topic_assessment','standing_practice') NOT NULL,
    question_type ENUM('mcq','short_answer') NOT NULL,
    question_text TEXT NOT NULL,
    model_answer TEXT NULL,
    grading_rubric TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_questions_topic (topic_id),
    KEY idx_questions_school_class_subject (school_id, class_id, subject_id),
    CONSTRAINT fk_q_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_q_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_q_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_q_topic FOREIGN KEY (topic_id) REFERENCES ilearning_topics(id) ON DELETE CASCADE,
    CONSTRAINT fk_q_teacher FOREIGN KEY (created_by) REFERENCES staff(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ilearning_question_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text VARCHAR(500) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_opt_question FOREIGN KEY (question_id) REFERENCES ilearning_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ilearning_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    topic_id INT NULL,
    pool_type ENUM('topic_assessment','standing_practice') NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL,
    status ENUM('in_progress','submitted','graded') NOT NULL DEFAULT 'in_progress',
    score_percentage DECIMAL(5,2) NULL,
    KEY idx_attempts_student_topic (student_id, topic_id),
    CONSTRAINT fk_att_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_topic FOREIGN KEY (topic_id) REFERENCES ilearning_topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ilearning_attempt_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_option_id INT NULL,
    free_text_answer TEXT NULL,
    is_correct TINYINT(1) NULL,
    points_awarded DECIMAL(5,2) NULL,
    teacher_feedback VARCHAR(500) NULL,
    graded_by INT NULL,               -- real staff.staff_id when a human (re)graded it
    graded_by_ai TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = current grade came from AiGrader, not a human
    graded_at TIMESTAMP NULL,
    KEY idx_answers_attempt (attempt_id),
    CONSTRAINT fk_ans_attempt FOREIGN KEY (attempt_id) REFERENCES ilearning_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ans_question FOREIGN KEY (question_id) REFERENCES ilearning_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_ans_option FOREIGN KEY (selected_option_id) REFERENCES ilearning_question_options(id) ON DELETE SET NULL,
    CONSTRAINT fk_ans_teacher FOREIGN KEY (graded_by) REFERENCES staff(staff_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ilearning_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    topic_id INT NOT NULL,
    opened_at TIMESTAMP NULL,
    last_ping_at TIMESTAMP NULL,
    percent_complete TINYINT UNSIGNED NOT NULL DEFAULT 0,
    time_spent_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
    completed_at TIMESTAMP NULL,
    feedback_note VARCHAR(500) NULL,
    feedback_by INT NULL,
    feedback_at TIMESTAMP NULL,
    UNIQUE KEY uniq_student_topic (student_id, topic_id),
    CONSTRAINT fk_prog_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_prog_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_prog_topic FOREIGN KEY (topic_id) REFERENCES ilearning_topics(id) ON DELETE CASCADE,
    CONSTRAINT fk_prog_teacher FOREIGN KEY (feedback_by) REFERENCES staff(staff_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

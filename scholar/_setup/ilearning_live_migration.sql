-- ============================================================
-- iLEARNING — LIVE CLASSES + IN-SESSION Q&A
-- ============================================================
-- room_reference is generated unguessably at schedule time
-- ('scholar-' . school_id . '-' . bin2hex(random_bytes(8))) and never
-- shown to a student whose class_id doesn't match the session.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < ilearning_live_migration.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS ilearning_live_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 40,
    room_reference VARCHAR(190) NOT NULL,
    status ENUM('scheduled','live','ended','canceled') NOT NULL DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_live_school_class (school_id, class_id),
    KEY idx_live_teacher (teacher_id),
    CONSTRAINT fk_live_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT fk_live_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_live_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    CONSTRAINT fk_live_teacher FOREIGN KEY (teacher_id) REFERENCES staff(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ilearning_live_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    left_at TIMESTAMP NULL,
    UNIQUE KEY uniq_session_student (session_id, student_id),
    CONSTRAINT fk_liveatt_session FOREIGN KEY (session_id) REFERENCES ilearning_live_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_liveatt_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS ilearning_live_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id INT NOT NULL,
    question_text VARCHAR(500) NOT NULL,
    visibility ENUM('private','public') NOT NULL DEFAULT 'public',
    status ENUM('pending','answered') NOT NULL DEFAULT 'pending',
    answer_text VARCHAR(1000) NULL,
    answered_by INT NULL,
    asked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    answered_at TIMESTAMP NULL,
    KEY idx_livequestions_session (session_id),
    CONSTRAINT fk_lq_session FOREIGN KEY (session_id) REFERENCES ilearning_live_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_lq_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_lq_teacher FOREIGN KEY (answered_by) REFERENCES staff(staff_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

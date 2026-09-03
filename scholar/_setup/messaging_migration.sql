-- ============================================================
-- SCHOLAR — STUDENT <-> TEACHER MESSAGING
-- ============================================================
-- One thread per (student, teacher) pair -- students.php's teacher list
-- (scholar/student_messages.php) is built from teacher_assignments +
-- classes.class_teacher_id for the student's own class, so a student can
-- only ever open a thread with a teacher who actually teaches them.
--
-- Apply with: mysql -u root scholar < messaging_migration.sql
-- ============================================================

USE scholar;

CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    teacher_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_teacher (student_id, teacher_id),
    KEY idx_school (school_id),
    KEY idx_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversation_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_role ENUM('student','teacher') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_conversation (conversation_id, created_at),
    CONSTRAINT fk_conv_msg_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

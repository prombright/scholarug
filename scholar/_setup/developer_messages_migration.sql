-- ============================================================
-- DEVELOPER MESSAGES -- school admin <-> developer chat
-- ============================================================
-- Replaces the old contact_developer.php design, which bridged into a
-- SEPARATE `abn_platform` database (devportal's own) that either never
-- existed on this environment or was otherwise unreachable -- that's why
-- the feature showed "Messaging is temporarily unavailable" instead of
-- actually working. This keeps everything in the `scholar` database next
-- to conversations/conversation_messages (the student<->teacher chat this
-- is deliberately modeled on), so there's no cross-database dependency
-- left to break.
--
-- One thread per school (UNIQUE(school_id)), same "reopen the same
-- thread" convention conversations.UNIQUE(student_id, teacher_id) uses.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < developer_messages_migration.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS developer_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dev_conv_school (school_id),
    CONSTRAINT fk_dev_conv_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS developer_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_role ENUM('school_admin','developer') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    KEY idx_dev_msg_conversation (conversation_id),
    CONSTRAINT fk_dev_msg_conversation FOREIGN KEY (conversation_id) REFERENCES developer_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- SCHOLAR — ROLES & ACCESS LAYER MIGRATION
-- ============================================================================
-- Safe to run once against your existing `scholar` database (phpMyAdmin ->
-- Import, or `mysql -u root scholar < scholar_roles_migration.sql`).
-- It only ADDS things and fixes one bad value — it does not delete data.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Fix the role naming inconsistency.
--    Some pages check $_SESSION['role'] === 'school_admin' (8 files),
--    others check === 'admin' (3 files). The DB enum only allowed 'admin'.
--    We standardize on 'school_admin' (the majority + the more descriptive
--    name, since 'admin' alone is ambiguous with 'developer').
-- ----------------------------------------------------------------------------
UPDATE users SET role = 'school_admin' WHERE role = 'admin';

-- Widen the enum to the full role set your platform actually needs.
ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'developer',
    'school_admin',
    'dos',
    'teacher',
    'headteacher',
    'bursar',
    'parent'
  ) NOT NULL;

-- ----------------------------------------------------------------------------
-- 2. "Class teacher" is not a separate role — it's an extra scope on top of
--    being a teacher. Model it as an assignment on the class itself.
-- ----------------------------------------------------------------------------
ALTER TABLE classes
  ADD COLUMN class_teacher_id INT NULL AFTER stream_name,
  ADD CONSTRAINT fk_classes_class_teacher
    FOREIGN KEY (class_teacher_id) REFERENCES staff(staff_id)
    ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- 3. Parents log in through the same `users` table as everyone else
--    (role = 'parent'), but a parent isn't staff and can have more than one
--    child — so we link them to students through a junction table.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parent_students (
  id INT NOT NULL AUTO_INCREMENT,
  school_id INT NOT NULL,
  user_id INT NOT NULL COMMENT 'FK to users.id where role=parent',
  student_id INT NOT NULL,
  relationship VARCHAR(50) DEFAULT 'Parent/Guardian',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_parent_student (user_id, student_id),
  KEY idx_parent_students_student (student_id),
  CONSTRAINT fk_parent_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_parent_students_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parents can read everything about their linked children but can't change
-- anything — the one thing they can write is feedback/questions to the school.
CREATE TABLE IF NOT EXISTS parent_feedback (
  id INT NOT NULL AUTO_INCREMENT,
  school_id INT NOT NULL,
  parent_user_id INT NOT NULL,
  student_id INT NOT NULL,
  subject VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('new','read','responded') NOT NULL DEFAULT 'new',
  response TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_parent_feedback_school (school_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4. Minimal `fees` table so the bursar role has something real to see.
--    Column names match exactly what school_admin/fees.php already
--    reads/writes (that file existed with zero backing table before this).
--    This is intentionally minimal — fee structures/invoicing are next-phase.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fees (
  id INT NOT NULL AUTO_INCREMENT,
  school_id INT NOT NULL,
  student_id INT NOT NULL,
  amount_paid DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash',
  reference VARCHAR(100) NULL,
  payment_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fees_school_student (school_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SCHOLAR — PROJECTS (UNEB O-Level project work, S.3-S.6)
-- ============================================================
-- One project per class. school_admin creates the project and assigns a
-- teacher to monitor it (scholar/school_admin/projects.php); that teacher
-- logs per-student stage updates with photo evidence
-- (scholar/projects/teacher_project.php). S.3-S.6-only is enforced in the
-- UI's class picker, not the schema -- same convention subject_catalog.php
-- already uses for its O-Level/A-Level split.
--
-- Compiling stages+evidence into one document for UNEB submission is a
-- deliberate "later" follow-up (per the user's own wording) -- every row
-- needed for that already exists here, queryable per class.
--
-- Apply with: mysql -u root scholar < projects_migration.sql
-- ============================================================

USE scholar;

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    class_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_school_class (school_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_teacher_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    teacher_id INT NOT NULL,
    UNIQUE KEY uniq_project_teacher (project_id, teacher_id),
    CONSTRAINT fk_pta_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_stages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    student_id INT NOT NULL,
    stage_title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    recorded_by INT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_project_student (project_id, student_id),
    CONSTRAINT fk_stage_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_stage_evidence (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stage_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_evidence_stage FOREIGN KEY (stage_id) REFERENCES project_stages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================
-- SCHOLAR — LEAVE MANAGEMENT
-- ============================================================
-- Self-service: any staff member applies via leave_requests.php (their
-- own staff_id only, ownership-scoped same as every other self-service
-- page in this app), HR/school_admin approves or rejects via
-- hr/leave_review.php.
--
-- Apply with: mysql -u root scholar < leave_management_migration.sql
-- ============================================================

USE scholar;

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    staff_id INT NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_school_staff (school_id, staff_id),
    KEY idx_school_status (school_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

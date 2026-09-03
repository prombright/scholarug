-- ============================================================
-- SCHOLAR — HR LOGIN ROLE
-- ============================================================
-- Adds the 'HR' login role, same pattern as the 'nurse' role added in
-- _setup/clinic_unification_migration.sql -- a staff member registered
-- with the "HR Manager" primary role (staff_manager.php) gets an 'HR'
-- users.role login, landing on hr_dashboard.php via role_destination().
-- school_admin keeps reaching the same HR pages too (require_role(['HR',
-- 'school_admin']) on each one) for schools with no dedicated HR person.
--
-- Apply with: mysql -u root scholar < hr_role_migration.sql
-- ============================================================

USE scholar;

ALTER TABLE users
    MODIFY COLUMN role ENUM('developer','school_admin','dos','teacher','headteacher','bursar','parent','student','nurse','HR') NOT NULL;

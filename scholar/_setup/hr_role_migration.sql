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

-- No hardcoded USE here on purpose -- this ran against a stray unrelated
-- "scholar" database on live (cPanel names it something like
-- yourcpanelusername_scholar) instead of the real one, while phpMyAdmin
-- reported success because THAT database really was created. Import/run
-- this against whichever database is already selected/specified.

ALTER TABLE users
    MODIFY COLUMN role ENUM('developer','school_admin','dos','teacher','headteacher','bursar','parent','student','nurse','HR') NOT NULL;

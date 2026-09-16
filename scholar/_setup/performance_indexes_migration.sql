-- ============================================================
-- SCHOLAR + ICLINIC — MISSING TENANT-SCOPE INDEXES
-- ============================================================
-- Every one of these tables is filtered by school_id on essentially
-- every query (multi-tenant isolation), but had no index on that column
-- -- meaning every such query was a full table scan. Found during the
-- 2026-08-04 security/performance pass. Apply with:
--   mysql -u root scholar < performance_indexes_migration.sql
--   mysql -u root iclinic < performance_indexes_migration.sql   (only the iclinic.users line applies there)
-- Safe to re-run: CREATE INDEX IF NOT EXISTS is idempotent.
-- ============================================================

-- No hardcoded USE here on purpose -- this ran against a stray unrelated
-- "scholar" database on live (cPanel names it something like
-- yourcpanelusername_scholar) instead of the real one, while phpMyAdmin
-- reported success because THAT database really was created. Import/run
-- this against whichever database is already selected/specified.

CREATE INDEX IF NOT EXISTS idx_account_verifications_school ON account_verifications (school_id);
CREATE INDEX IF NOT EXISTS idx_announcements_school ON announcements (school_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_school ON audit_logs (school_id);
CREATE INDEX IF NOT EXISTS idx_events_school ON events (school_id);
CREATE INDEX IF NOT EXISTS idx_fee_payments_school ON fee_payments (school_id);
CREATE INDEX IF NOT EXISTS idx_guardians_school ON guardians (school_id);
CREATE INDEX IF NOT EXISTS idx_notifications_school ON notifications (school_id);
CREATE INDEX IF NOT EXISTS idx_parent_students_school ON parent_students (school_id);
CREATE INDEX IF NOT EXISTS idx_staff_departments_school ON staff_departments (school_id);
CREATE INDEX IF NOT EXISTS idx_streams_school ON streams (school_id);
CREATE INDEX IF NOT EXISTS idx_system_subjects_school ON system_subjects (school_id);
CREATE INDEX IF NOT EXISTS idx_teacher_assignments_school ON teacher_assignments (school_id);

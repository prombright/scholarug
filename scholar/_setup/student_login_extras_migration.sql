-- ============================================================
-- SCHOLAR — STUDENT LOGIN EXTRAS (temp password visibility + reset requests)
-- ============================================================
-- Two additions to `users`, both scoped to the student-login flow only:
--
-- `temp_password_plain` -- the current temp password in plain text, kept
-- ONLY while it's still temporary. Set whenever a temp password is issued
-- (auto-created on student add/import, or admin-regenerated later) and
-- cleared to NULL the instant force_password_reset.php flips
-- is_temp_password to 0. This isn't a new exposure: the app already treats
-- the original temp password as non-secret and re-derivable from
-- students.student_no forever (even after the student changes it) --
-- this column just makes that work for genuinely-random regenerated
-- passwords too, and is actually tighter than today since it clears on
-- activation instead of staying visible indefinitely.
--
-- `reset_requested` -- set by a class teacher (scholar/teacher_class_logins.php)
-- when a student's password is already changed and can no longer be shown;
-- cleared automatically the next time a school_admin regenerates that
-- student's password.
--
-- Apply with: mysql -u root scholar < student_login_extras_migration.sql
-- ============================================================

USE scholar;

ALTER TABLE users
    ADD COLUMN temp_password_plain VARCHAR(20) NULL DEFAULT NULL AFTER is_temp_password,
    ADD COLUMN reset_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER temp_password_plain;

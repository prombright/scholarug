-- ============================================================
-- MULTI-SCHOOL STAFF LOGIN -- same email/phone, different school
-- ============================================================
-- A teacher who works at more than one Scholar school gets a SEPARATE
-- `staff` row (and a separate `users` login row) per school -- that's
-- already how assignment works. What was blocking them from using the
-- SAME email/phone for both: `users.email` and `staff.phone` were each
-- UNIQUE across the *entire* table, not per school, so a second school
-- admin registering the same person with the same email/phone got a
-- duplicate-key error.
--
-- Replaced with a composite UNIQUE(school_id, email) / UNIQUE(school_id,
-- phone) -- the same email/phone can now recur once per school, but two
-- DIFFERENT people at the SAME school still can't collide on either.
-- login.php was updated separately to try every matching row's password
-- instead of just the first one it finds, so the password itself is what
-- decides which school the teacher lands in.
--
-- Run against the `scholar` database:
--   mysql -u root scholar < multi_school_login_migration.sql
-- ============================================================

ALTER TABLE users DROP INDEX `email`;
ALTER TABLE users ADD UNIQUE KEY `uniq_school_email` (`school_id`, `email`);

-- This key is named `username` in the existing schema even though it sits
-- on the `phone` column -- a pre-existing naming quirk, not something this
-- migration introduces.
ALTER TABLE staff DROP INDEX `username`;
ALTER TABLE staff ADD UNIQUE KEY `uniq_school_phone` (`school_id`, `phone`);

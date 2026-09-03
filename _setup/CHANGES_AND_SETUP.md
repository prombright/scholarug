# Scholar — Roles & Access Layer Patch

This fixes the roles layer and adds the roles you described (headteacher,
bursar, parent, class teacher) on top of what Scholar already has
(developer, school_admin, dos, teacher).

## 1. What was actually broken (found by reading the code, not guessing)

- `login.php` had no redirect case for `dos` — DOS users logged in and got
  bounced back to the marketing site. Same gap existed for the new roles.
- The `users.role` database column only allowed `admin`, but the login
  code sets the session role to `school_admin`. 8 files checked for
  `school_admin`, 3 files (`settings.php`, `teachers_portal.php`,
  `process_teacher_action.php`) checked for `admin`. Depending on how an
  admin logged in, half the app either worked or silently denied them.
- `settings.php` had a leftover "DEV OVERRIDE" that faked every visitor's
  session as `role=admin, school_id=1` — meaning settings had **no real
  access control** at all. Removed.
- `school_admin/fees.php` queried `s.student_name` and `s.class_id` on the
  `students` table — neither column exists (it's `full_name` and a plain
  text `class_name`, no FK). Fixed so the bursar's fees page actually runs
  instead of throwing a SQL error the first time it's opened.

## 2. What's new

| Role | File | What it does |
|---|---|---|
| headteacher | `headteacher_dashboard.php` | Read-only: student/staff counts, classes + class teachers, today's attendance, announcements. No forms — every query is a SELECT. |
| dos | `dos_dashboard.php` | Read-only subject-teacher coverage per class, so gaps are visible before term starts. Marks/reports will populate here once that phase is built (see below). |
| bursar | `bursar_dashboard.php` | Collections summary, links into the fixed `fees.php` ledger. |
| parent | `parent_portal.php` | Shows only the parent's own linked children (enforced in every query via `parent_students`), their attendance, and a feedback form — the *only* thing a parent can write. |
| school_admin | `school_admin/manage_parents.php` | Create a parent login and link it to one or more children. Without this, the parent role has nowhere to attach — a parent account is useless until a student is linked. |
| all | `auth_guard.php` | One `require_role([...])` call to drop at the top of any page. `developer` always passes. Non-developer roles are also checked against `school_id` so a session without a tenant can't view anything. |

"Class teacher" is **not** a new role — it's an extra scope on top of
`teacher`. `classes.class_teacher_id` marks who that is; use
`is_class_teacher_of($pdo, $staff_id, $class_id)` from `auth_guard.php`
wherever a teacher's page needs to show more than their own subject (e.g.
attendance/performance across all subjects for their own class).

## 3. How to apply this

1. **Back up your database first.** `mysqldump scholar > scholar_backup.sql`
2. Run `scholar_roles_migration.sql` against your `scholar` database
   (phpMyAdmin → Import, or `mysql -u root scholar < scholar_roles_migration.sql`).
3. Copy every file under `scholar/` in this patch into your real `scholar/`
   folder, preserving the same paths (they overwrite the originals —
   diff them first if you've since made local edits to the same files).
4. In your school admin panel, open **Manage Parent Accounts**
   (`school_admin/manage_parents.php`) and link at least one parent to a
   student so you have something to log in and test with.
5. To test the other new roles quickly, insert a row directly, e.g.:
   ```sql
   INSERT INTO users (school_id, username, password, role, account_status)
   VALUES (1, 'head1', '$2y$10$examplehashreplaceme', 'headteacher', 'active');
   ```
   Generate a real bcrypt hash with `password_hash('yourpassword', PASSWORD_BCRYPT)`
   in a throwaway PHP snippet, or reuse the temporary password logic in
   `manage_parents.php` as a template.
6. Log in at `scholar/login.php` as each role and confirm the redirect
   lands on the right dashboard and that a *different* role's dashboard
   returns "Access denied" instead of loading.

## 4. What I'd extend next (in order)

1. **Marks → report pipeline.** `process_marks.php` writes to
   `terminal_marks`; `generate_report.php` reads from `student_marks`.
   Neither table exists. This is the next real gap to close — it's what
   makes "teacher enters marks → printable report" actually work, and it's
   what the DOS dashboard above is waiting to show real data from.
2. **Fees structure**, not just the raw ledger — `fees_structure.php`
   exists as a page but nothing backs it yet.
3. **Class teacher assignment UI** — right now `class_teacher_id` has to be
   set via SQL; give the school admin a dropdown on the classes screen.
4. **Wire the unused `roles`/`permissions`/`role_permissions` tables**, or
   drop them — right now they exist but nothing reads them, which is
   exactly the kind of parallel system that caused the `admin` vs
   `school_admin` split in the first place.
5. Once Scholar's role layer is solid end-to-end, come back to embedding
   the iClinic database (we already mapped that plan out — iClinic's
   `students` table gets retired in favor of Scholar's, and every
   `clinic_*` table gets a `school_id`).

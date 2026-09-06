<?php
declare(strict_types=1);


// |--------------------------------------------------------------------------
// | SCHOLAR — CENTRAL ACCESS GUARD
// |--------------------------------------------------------------------------
// | One place that decides "is this session allowed to see this page".
// | Every protected page should start with:
    // require_once __DIR__ . '/auth_guard.php';        // from scholar/*.php
    // require_once __DIR__ . '/../auth_guard.php';     // from scholar/school_admin/*.php

    // require_role(['headteacher', 'developer']);

    require_once __DIR__ . '/config.php'; // defines SCHOLAR_BASE in one place
    // scholar_class_ladder()/scholar_normalize_class_name() live in
    // _subject_helpers.php (moved out of this file) so plain PHP pages that
    // just need class/subject helpers -- e.g. scholar/developer/*.php --
    // can require that file alone instead of pulling in this file's
    // session cookie config, idle-timeout redirect, and role machinery.
    require_once __DIR__ . '/_subject_helpers.php';


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}

// Idle timeout: 30 minutes with no request forces a fresh login next time,
// regardless of how long the browser itself keeps the session cookie alive.
// The cookie is already a proper session cookie (expires when the browser
// truly closes) -- but some browsers (Chrome/Edge "continue where you left
// off", background-apps mode, restoring tabs after a restart) keep it alive
// well past what a user would consider "closed", which otherwise means a
// stale login lingers indefinitely.
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 1800) {
        $_SESSION = [];
        session_destroy();
        header("Location: " . SCHOLAR_BASE . "/login.php?timeout=1");
        exit();
    }
    $_SESSION['last_activity'] = time();
}


$SCHOLAR_BASE = SCHOLAR_BASE;
$SCHOOL_NAME = $_SESSION['school_name'] ?? 'Scholar';
$ACTIVE_NAV   = $ACTIVE_NAV ?? '';




/**
 * Stop the request unless the logged-in user's role is in $allowed_roles.
 * 'developer' (the platform super-admin) is always allowed through, so you
 * never need to remember to add it to every call site.
 */
function require_role(array $allowed_roles): void
{
    if (!isset($_SESSION['role']) || !isset($_SESSION['user_id'])) {
        header("Location: " . SCHOLAR_BASE . "/login.php");
        exit();
    }

    // $role = $_SESSION['role'];

    if ($_SESSION['role'] === 'developer') {
        return; // platform super-admin can see everything
    }

    if (!in_array($_SESSION['role'], $allowed_roles, true)) {
       header("Location: " . SCHOLAR_BASE . "/dashboard.php");
       exit();

    }

    // Every non-developer role in Scholar is scoped to one school (tenant).
    if (!isset($_SESSION['school_id'])) {
        http_response_code(403);
        die('No school context on this session — please log in again.');
    }

    // Subscription soft-lock: every request re-checks the school's
    // subscription live (self-healing the instant a payment succeeds, no
    // cron dependency) and flags the session rather than blocking the
    // request -- dashboards/reports stay usable, only specific mutating
    // pages (e.g. student admission) call require_active_subscription().
    //
    // `global $pdo` before require_once matters here: if the calling page
    // already required db.php at top level, require_once below is a
    // no-op and this just aliases the existing global $pdo. If this is
    // the first inclusion (some pages call require_role() before their
    // own require db.php), the `global` binding makes db.php's
    // `$pdo = new PDO(...)` write to the real global, not a copy local to
    // this function -- without it, $pdo would be undefined here whenever
    // db.php happened to already be loaded.
    global $pdo;
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../payments/config.php';
    $_SESSION['subscription_locked'] = scholar_subscription_is_locked($pdo, (int) $_SESSION['school_id']);

    // Guarantee the school's class ladder (S.1-S.6, or Primary's Baby/
    // Middle/Top Class + P.1-P.7) already exists wherever classes get
    // referenced -- a teacher's mark-entry dropdown, students.php's Add
    // Student form, subject_catalog.php -- not just after a school_admin
    // happens to visit classes.php first (classes.php's own auto-heal
    // still runs too; this just means every OTHER page gets the same
    // guarantee). Session-flagged so it's one extra query pair on the
    // first protected page of a session, not every request.
    if (($_SESSION['classes_seeded_for'] ?? null) !== $_SESSION['school_id']) {
        $school_type_stmt = $pdo->prepare('SELECT school_type FROM schools WHERE id = ?');
        $school_type_stmt->execute([$_SESSION['school_id']]);
        $school_type = $school_type_stmt->fetchColumn() ?: 'Secondary';
        scholar_provision_school_type_defaults($pdo, (int) $_SESSION['school_id'], $school_type);
        $_SESSION['classes_seeded_for'] = $_SESSION['school_id'];
    }
}

/**
 * Call instead of (or after) require_role() on the specific mutating
 * actions that should be gated on a healthy subscription. Everything else
 * -- reports, dashboards, browsing -- stays usable through a lapse.
 */
function require_active_subscription(): void
{
    if (($_SESSION['subscription_locked'] ?? false) === true) {
        header("Location: " . SCHOLAR_BASE . "/renew.php");
        exit();
    }
}

function scholar_subscription_is_locked(PDO $pdo, int $schoolId): bool
{
    $stmt = $pdo->prepare(
        "SELECT status, trial_ends_at, current_period_end FROM subscriptions WHERE school_id = ?"
    );
    $stmt->execute([$schoolId]);
    $sub = $stmt->fetch();

    // No subscription row at all (e.g. a school created before this
    // feature existed) — don't lock; onboarding backfills this over time
    // rather than retroactively locking existing schools.
    if (!$sub) {
        return false;
    }

    if (in_array($sub['status'], ['expired', 'canceled'], true)) {
        return true;
    }

    $deadline = $sub['status'] === 'trialing' ? $sub['trial_ends_at'] : $sub['current_period_end'];
    if ($deadline === null) {
        return false;
    }

    $graceDays = (int) PAYMENTS_GRACE_PERIOD_DAYS;
    return strtotime($deadline) < strtotime("-{$graceDays} days");
}

/**
 * The iLearning live-class add-on -- a SEPARATE purchase from the base
 * Scholar subscription above (a school has exactly one `subscriptions`
 * row, UNIQUE(school_id), so this add-on gets its own `ilearning_addons`
 * table rather than overloading that row's meaning).
 *
 * Deliberate inversion from scholar_subscription_is_locked() above, called
 * out so it's never mistaken for a copy-paste bug: the base subscription
 * check defaults to UNLOCKED when no row exists (pre-existing schools
 * shouldn't be retroactively punished for a feature that didn't exist
 * yet), but this one defaults to LOCKED when no `ilearning_addons` row
 * exists -- a school that never bought the add-on shouldn't get live
 * classes for free just because the row is missing.
 */
function scholar_ilearning_addon_is_active(PDO $pdo, int $schoolId): bool
{
    $stmt = $pdo->prepare(
        "SELECT status, trial_ends_at, current_period_end FROM ilearning_addons WHERE school_id = ?"
    );
    $stmt->execute([$schoolId]);
    $addon = $stmt->fetch();

    if (!$addon) {
        return false;
    }

    if (in_array($addon['status'], ['expired', 'canceled'], true)) {
        return false;
    }

    $deadline = $addon['status'] === 'trialing' ? $addon['trial_ends_at'] : $addon['current_period_end'];
    if ($deadline === null) {
        return true;
    }

    $graceDays = (int) PAYMENTS_GRACE_PERIOD_DAYS;
    return strtotime($deadline) >= strtotime("-{$graceDays} days");
}

/**
 * Call on the two iLearning live-class entry points (schedule/create as a
 * teacher, "Join" as a student) -- everything else in iLearning (notes,
 * auto-marked assessments, practice, progress tracking) stays in the base
 * Scholar subscription and never calls this.
 */
function require_ilearning_addon(): void
{
    global $pdo;
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../payments/config.php';

    if (!scholar_ilearning_addon_is_active($pdo, current_school_id())) {
        header("Location: " . SCHOLAR_BASE . "/ilearning/addon_upgrade.php");
        exit();
    }
}

function current_school_id(): int{
    return $_SESSION['school_id'] ?? 0;
}

function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? 0;
}

function current_student_id(): int
{
    return $_SESSION['student_id'] ?? 0;
}

function current_staff_id(): int
{
    return $_SESSION['staff_id'] ?? 0;
}

function current_term(): string
{
    return $_SESSION['current_term'] ?? 'Term 1';
}

function current_year(): string
{
    return $_SESSION['current_year'] ?? (string) date('Y');
}

/**
 * STU-{year}-{4-digit sequence}, same convention/pattern as
 * staff_manager.php's staff_code generator (STF-{year}-####) --
 * students.student_no is a globally unique column (not scoped per
 * school), same as staff.staff_code, so the sequence lookup is global
 * too, not filtered by school_id. Lives here (not in students.php itself)
 * so _setup/backfill_missing_student_numbers.php can use the exact same
 * generator without loading that whole page.
 */
function scholar_generate_student_no(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "STU-{$year}-";
    $seq = $pdo->prepare("SELECT student_no FROM students WHERE student_no LIKE ? ORDER BY student_no DESC LIMIT 1");
    $seq->execute([$prefix . '%']);
    $last = $seq->fetchColumn();
    $next = $last ? str_pad((string) ((int) substr($last, -4) + 1), 4, '0', STR_PAD_LEFT) : '0001';
    return $prefix . $next;
}

/**
 * STF-{year}-{4-digit sequence} -- same convention as
 * scholar_generate_student_no() above, extracted out of staff_manager.php's
 * "add" handler so its bulk CSV import can generate collision-free codes
 * the same way a single "Register Staff Member" submission does, instead of
 * two copies of this sequence lookup silently drifting apart.
 */
function scholar_generate_staff_code(PDO $pdo): string
{
    $prefix = 'STF-' . date('Y') . '-';
    $seq = $pdo->prepare("SELECT staff_code FROM staff WHERE staff_code LIKE ? ORDER BY staff_code DESC LIMIT 1");
    $seq->execute([$prefix . '%']);
    $last = $seq->fetchColumn();
    $next = $last ? str_pad((string) ((int) substr($last, -4) + 1), 4, '0', STR_PAD_LEFT) : '0001';
    return $prefix . $next;
}

/**
 * Single source of truth for "which page does this role land on after
 * login or a forced password reset". Previously duplicated (with
 * different, drifted contents) in login.php in two places and again in
 * force_password_reset.php -- one of those copies never got 'student'
 * added, so a student could authenticate successfully and still land on
 * index.php. Every place that redirects by role should call this instead
 * of hand-rolling another switch/array.
 */
function role_destination(string $role): string
{
    $map = [
        'developer'    => 'developer/developer_dashboard.php',
        'school_admin' => 'app_admin.php',
        'teacher'      => 'app_teacher.php',
        'dos'          => 'dos_dashboard.php',
        'headteacher'  => 'headteacher_dashboard.php',
        'bursar'       => 'bursar_dashboard.php',
        'parent'       => 'parent_portal.php',
        'student'      => 'app_student.php',
        'nurse'        => 'nurse_dashboard.php',
        'hr'           => 'app_hr.php',
    ];

    return $map[$role] ?? 'index.php';
}

/**
 * Is $staff_id the official class teacher of $class_id (within their own school)?
 * Class teacher is not a role — it's an extra scope layered on top of 'teacher':
 * a class teacher can see attendance/performance across ALL subjects for their
 * class, not just the subject(s) they personally teach.
 */
function is_class_teacher_of(PDO $pdo, int $staff_id, int $class_id): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM classes WHERE id = ? AND class_teacher_id = ? AND school_id = ? LIMIT 1"
    );
    $stmt->execute([$class_id, $staff_id, current_school_id()]);
    return (bool) $stmt->fetchColumn();
}

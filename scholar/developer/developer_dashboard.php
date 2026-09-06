<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require '../db.php';
require_once __DIR__ . '/../_subject_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| SUPER ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'developer' ||
    !isset($_SESSION['user_id'])
) {
    header('Location: login.php');
    exit;
}

$developer_id = (int) $_SESSION['user_id'];
$msg = '';
$msg_type = 'success';

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['developer_csrf_token'])) {
    $_SESSION['developer_csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['developer_csrf_token'];

function verify_csrf_token(): void
{
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['developer_csrf_token'] ?? '',
            $_POST['csrf_token']
        )
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }
}

/*
|--------------------------------------------------------------------------
| HELPER: REDIRECT BACK TO DASHBOARD
|--------------------------------------------------------------------------
*/

function redirect_dashboard(): never
{
    header('Location: developer_dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| DEVELOPER PROFILE
|--------------------------------------------------------------------------
*/

$profile_stmt = $pdo->prepare(
    "SELECT id, username, email, role
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$profile_stmt->execute([$developer_id]);

$developer = $profile_stmt->fetch(PDO::FETCH_ASSOC);

if (!$developer) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$dev_user = $developer['username'] ?? 'Super Admin';

/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf_token();

    /*
    |--------------------------------------------------------------------------
    | UPDATE DEVELOPER PROFILE
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['update_profile'])) {

        $new_user = trim($_POST['developer_username'] ?? '');
        $new_pass = trim($_POST['developer_password'] ?? '');

        if ($new_user === '') {

            $msg = 'Developer username cannot be empty.';
            $msg_type = 'error';

        } else {

            try {

                if ($new_pass !== '') {

                    if (strlen($new_pass) < 8) {
                        throw new Exception(
                            'Password must contain at least 8 characters.'
                        );
                    }

                    $hashed_pass = password_hash(
                        $new_pass,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare(
                        "UPDATE users
                         SET username = ?, password = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([
                        $new_user,
                        $hashed_pass,
                        $developer_id
                    ]);

                } else {

                    $stmt = $pdo->prepare(
                        "UPDATE users
                         SET username = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([
                        $new_user,
                        $developer_id
                    ]);
                }

                $_SESSION['username'] = $new_user;

                $msg = 'Developer account updated successfully.';
                $msg_type = 'success';

            } catch (Throwable $e) {

                $msg = 'Unable to update developer account: ' .
                    $e->getMessage();

                $msg_type = 'error';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTER / PROVISION SCHOOL
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['provision_school'])) {

        $school_name = trim($_POST['school_name'] ?? '');
        $school_type = $_POST['school_type'] ?? '';

        if ($school_name === '') {

            $msg = 'School name is required.';
            $msg_type = 'error';

        } elseif (!in_array($school_type, ['Primary', 'Secondary'], true)) {

            // This form used to insert with no school_type at all, silently
            // falling back to the schools table's DEFAULT 'Secondary' --
            // meaning a Primary school registered here got Secondary's
            // O-Level/A-Level classes (or, before that default existed,
            // nothing at all) and its admin had no idea why. Same
            // required-select school_onboarding.php already uses.
            $msg = 'Please select a school type.';
            $msg_type = 'error';

        } else {

            try {

                /*
                | Use the highest existing numeric school code rather
                | than COUNT(*), which can produce duplicate codes
                | after a school is deleted.
                */

                $code_stmt = $pdo->query(
                    "SELECT school_code
                     FROM schools
                     WHERE school_code LIKE 'SC-%'
                     ORDER BY id DESC
                     LIMIT 1"
                );

                $last_code = $code_stmt->fetchColumn();

                $next_numeric_id = 1;

                if ($last_code) {

                    $numeric_part = (int) preg_replace(
                        '/[^0-9]/',
                        '',
                        (string) $last_code
                    );

                    $next_numeric_id = $numeric_part + 1;
                }

                $school_code = 'SC-' .
                    str_pad(
                        (string) $next_numeric_id,
                        3,
                        '0',
                        STR_PAD_LEFT
                    );

                /*
                | Generate a temporary school setup PIN.
                |
                | This is NOT treated as a user password.
                */

                $random_pin = (string) random_int(
                    10000,
                    99999
                );

                $ins = $pdo->prepare(
                    "INSERT INTO schools
                    (
                        school_name,
                        school_code,
                        access_pin,
                        school_type,
                        payment_status,
                        is_active
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        'pending',
                        1
                    )"
                );

                $ins->execute([
                    $school_name,
                    $school_code,
                    $random_pin,
                    $school_type
                ]);

                // Same provisioning school_onboarding.php's own "Provision
                // School" button runs -- builds this type's class ladder
                // and seeds its default subjects, so this school doesn't
                // land on an empty Classes page like it used to.
                scholar_provision_school_type_defaults(
                    $pdo,
                    (int) $pdo->lastInsertId(),
                    $school_type
                );

                $msg =
                    "School registered successfully. " .
                    "School Code: {$school_code} | " .
                    "Setup PIN: {$random_pin}";

                $msg_type = 'success';

            } catch (Throwable $e) {

                $msg =
                    'Unable to register school: ' .
                    $e->getMessage();

                $msg_type = 'error';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE PAYMENT STATUS
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['toggle_payment'])) {

        $school_id = (int) ($_POST['school_id'] ?? 0);

        if ($school_id > 0) {

            try {

                $stmt = $pdo->prepare(
                    "SELECT payment_status
                     FROM schools
                     WHERE id = ?
                     LIMIT 1"
                );

                $stmt->execute([$school_id]);

                $current_status = $stmt->fetchColumn();

                if ($current_status === false) {
                    throw new Exception('School not found.');
                }

                $new_status =
                    ($current_status === 'paid')
                    ? 'pending'
                    : 'paid';

                $upd = $pdo->prepare(
                    "UPDATE schools
                     SET payment_status = ?
                     WHERE id = ?"
                );

                $upd->execute([
                    $new_status,
                    $school_id
                ]);

                $msg =
                    'School payment status changed to ' .
                    strtoupper($new_status) . '.';

            } catch (Throwable $e) {

                $msg =
                    'Unable to update payment status: ' .
                    $e->getMessage();

                $msg_type = 'error';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE / SUSPEND SCHOOL
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['toggle_active'])) {

        $school_id = (int) ($_POST['school_id'] ?? 0);

        if ($school_id > 0) {

            try {

                $stmt = $pdo->prepare(
                    "SELECT is_active
                     FROM schools
                     WHERE id = ?
                     LIMIT 1"
                );

                $stmt->execute([$school_id]);

                $current_state = $stmt->fetchColumn();

                if ($current_state === false) {
                    throw new Exception('School not found.');
                }

                $new_state =
                    ((int) $current_state === 1)
                    ? 0
                    : 1;

                $upd = $pdo->prepare(
                    "UPDATE schools
                     SET is_active = ?
                     WHERE id = ?"
                );

                $upd->execute([
                    $new_state,
                    $school_id
                ]);

                $msg =
                    $new_state === 1
                    ? 'School activated successfully.'
                    : 'School suspended successfully.';

            } catch (Throwable $e) {

                $msg =
                    'Unable to change school status: ' .
                    $e->getMessage();

                $msg_type = 'error';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ARCHIVE SCHOOL
    |--------------------------------------------------------------------------
    |
    | For now, we do NOT permanently delete the school.
    |
    | We simply deactivate it.
    |
    | Later we will introduce a proper archived_at column.
    |
    */

    if (isset($_POST['archive_school'])) {

        $school_id = (int) ($_POST['school_id'] ?? 0);

        if ($school_id > 0) {

            try {

                $upd = $pdo->prepare(
                    "UPDATE schools
                     SET is_active = 0
                     WHERE id = ?"
                );

                $upd->execute([
                    $school_id
                ]);

                $msg =
                    'School has been suspended/archived from active operations.';

            } catch (Throwable $e) {

                $msg =
                    'Unable to archive school: ' .
                    $e->getMessage();

                $msg_type = 'error';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/

/*
| Total schools
*/

$total_schools = (int) $pdo
    ->query("SELECT COUNT(*) FROM schools")
    ->fetchColumn();

/*
| Active schools
*/

$active_schools = (int) $pdo
    ->query(
        "SELECT COUNT(*)
         FROM schools
         WHERE is_active = 1"
    )
    ->fetchColumn();

/*
| Suspended / inactive schools
*/

$suspended_schools = (int) $pdo
    ->query(
        "SELECT COUNT(*)
         FROM schools
         WHERE is_active = 0"
    )
    ->fetchColumn();

/*
| Pending payment schools
*/

$pending_payment_schools = (int) $pdo
    ->query(
        "SELECT COUNT(*)
         FROM schools
         WHERE payment_status != 'paid'
            OR payment_status IS NULL"
    )
    ->fetchColumn();

/*
| Total learners
|
| We count students from the Scholar students table.
|
| This assumes students.school_id exists.
*/

try {

    $total_learners = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM students"
        )
        ->fetchColumn();

} catch (Throwable $e) {

    $total_learners = 0;
}

/*
| Total teachers
*/

try {

    $total_teachers = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM teachers"
        )
        ->fetchColumn();

} catch (Throwable $e) {

    $total_teachers = 0;
}

/*
| Total staff
*/

try {

    $total_staff = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM staff"
        )
        ->fetchColumn();

} catch (Throwable $e) {

    $total_staff = 0;
}

/*
| Total users
*/

try {

    $total_users = (int) $pdo
        ->query(
            "SELECT COUNT(*)
             FROM users"
        )
        ->fetchColumn();

} catch (Throwable $e) {

    $total_users = 0;
}

/*
|--------------------------------------------------------------------------
| RECENT SCHOOLS
|--------------------------------------------------------------------------
*/

$recent_schools_stmt = $pdo->query(
    "SELECT *
     FROM schools
     ORDER BY id DESC
     LIMIT 10"
);

$recent_schools =
    $recent_schools_stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| SCHOOL USER COUNTS
|--------------------------------------------------------------------------
|
| This uses users.school_id.
|
*/

$school_user_counts = [];

try {

    $user_count_stmt = $pdo->query(
        "SELECT
            school_id,
            COUNT(*) AS total_users
         FROM users
         WHERE school_id IS NOT NULL
         GROUP BY school_id"
    );

    foreach (
        $user_count_stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {

        $school_user_counts[
            (int) $row['school_id']
        ] = (int) $row['total_users'];
    }

} catch (Throwable $e) {

    $school_user_counts = [];
}

/*
|--------------------------------------------------------------------------
| FORMAT DATE
|--------------------------------------------------------------------------
*/

function format_dashboard_date(
    ?string $date
): string {

    if (!$date) {
        return 'Not available';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return 'Not available';
    }

    return date(
        'd M Y',
        $timestamp
    );
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    name="robots"
    content="noindex, nofollow, noarchive"
>

<title>
    ScholarUg | Super Admin Control Center
</title>

<style>


:root {
    --bg: #080b11;
    --panel: #0d1118;
    --border: #1e293b;
    --text: #e2e8f0;
    --muted: #64748b;
}

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    font-family:
        Inter,
        "Segoe UI",
        system-ui,
        -apple-system,
        sans-serif;

    background:
        var(--bg);

    color:
        var(--text);
}

a {
    color: inherit;
}

button,
input {
    font-family: inherit;
}

.container {

    width: 100%;

    max-width: 1600px;

    margin: auto;

    padding: 30px;
}

/* HEADER */

.topbar {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    padding-bottom: 25px;

    border-bottom:
        1px solid var(--border);

    margin-bottom: 30px;
}

.brand {

    display: flex;

    align-items: center;

    gap: 15px;
}

.logo {

    width: 170px;

    height: 52px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #a855f7,
            #06b6d4
        );

    color: white;

    font-size: 1.05rem;

    font-weight: 800;

    letter-spacing: .3px;

    white-space: nowrap;

    box-shadow:
        0 0 25px
        rgba(168, 85, 247, .25);
}

.brand-info h1 {

    margin: 0;

    font-size: 1rem;

    color: var(--text);
}

.brand-info p {

    margin: 4px 0 0;

    color: var(--muted);

    font-size: .75rem;
}

.logout {

    color: #f87171;

    text-decoration: none;

    border:
        1px solid
        rgba(239, 68, 68, .25);

    padding:
        10px 18px;

    border-radius: 7px;

    font-size: .8rem;

    font-weight: 700;
}

.logout:hover {

    background:
        rgba(239, 68, 68, .08);
}

/* ALERT */

.alert {

    padding: 15px 18px;

    border-radius: 8px;

    margin-bottom: 25px;

    font-size: .85rem;
}

.alert.success {

    background:
        rgba(16, 185, 129, .08);

    border:
        1px solid
        rgba(16, 185, 129, .25);

    color: #6ee7b7;
}

.alert.error {

    background:
        rgba(239, 68, 68, .08);

    border:
        1px solid
        rgba(239, 68, 68, .25);

    color: #fca5a5;
}

/* STATISTICS */

.stats-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 18px;

    margin-bottom: 25px;
}

.stat-card {

    background: var(--panel);

    border:
        1px solid var(--border);

    border-radius: 12px;

    padding: 22px;
}

.stat-label {

    color: var(--muted);

    font-size: .7rem;

    text-transform: uppercase;

    letter-spacing: .8px;

    font-weight: 700;
}

.stat-number {

    margin-top: 12px;

    font-size: 2rem;

    font-weight: 800;

    color: var(--text);
}

.stat-description {

    margin-top: 5px;

    font-size: .75rem;

    color: var(--muted);
}

/* MAIN GRID */

.main-grid {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 22px;

    margin-bottom: 25px;
}

.card {

    background: var(--panel);

    border:
        1px solid var(--border);

    border-radius: 12px;

    padding: 23px;
}

.card h2 {

    margin: 0 0 20px;

    font-size: .8rem;

    text-transform: uppercase;

    letter-spacing: 1px;

    color: var(--muted);
}

/* FORM */

.form-group {

    margin-bottom: 17px;
}

.form-group label {

    display: block;

    margin-bottom: 7px;

    color: var(--muted);

    font-size: .7rem;

    text-transform: uppercase;

    font-weight: 700;
}

.form-control {

    width: 100%;

    padding: 12px 14px;

    border:
        1px solid var(--border);

    border-radius: 7px;

    background: var(--bg);

    color: var(--text);

    outline: none;
}

.form-control:focus {

    border-color:
        #a855f7;
}

.btn {

    border: none;

    padding: 12px 16px;

    border-radius: 7px;

    cursor: pointer;

    font-weight: 700;

    font-size: .75rem;

    text-transform: uppercase;
}

.btn-primary {

    background:
        #a855f7;

    color: white;
}

.btn-cyan {

    background:
        #06b6d4;

    color: white;
}

/* ALERT ITEMS */

.alert-list {

    display: flex;

    flex-direction: column;

    gap: 10px;
}

.alert-item {

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 13px;

    border-radius: 7px;

    background: var(--bg);

    border:
        1px solid var(--border);

    font-size: .8rem;
}

.alert-danger {

    border-left:
        3px solid #ef4444;
}

.alert-warning {

    border-left:
        3px solid #f59e0b;
}

.alert-success {

    border-left:
        3px solid #10b981;
}

/* TABLE */

.table-card {

    background: var(--panel);

    border:
        1px solid var(--border);

    border-radius: 12px;

    padding: 23px;

    overflow: hidden;
}

.table-wrapper {

    overflow-x: auto;
}

table {

    width: 100%;

    border-collapse: collapse;

    min-width: 900px;
}

th {

    text-align: left;

    padding: 13px 10px;

    border-bottom:
        1px solid var(--border);

    color: var(--muted);

    font-size: .65rem;

    text-transform: uppercase;

    letter-spacing: .7px;
}

td {

    padding: 15px 10px;

    border-bottom:
        1px solid var(--border);

    font-size: .8rem;
}

.school-code {

    color: #06b6d4;

    font-family: monospace;

    font-weight: 700;
}

.school-name {

    color: var(--text);

    font-weight: 700;

    margin-top: 4px;
}

.badge {

    display: inline-block;

    padding:
        5px 9px;

    border-radius: 5px;

    font-size: .65rem;

    font-weight: 700;

    text-transform: uppercase;
}

.badge-paid {

    color: #10b981;

    background:
        rgba(16, 185, 129, .08);
}

.badge-pending {

    color: #f59e0b;

    background:
        rgba(245, 158, 11, .08);
}

.badge-active {

    color: #06b6d4;

    background:
        rgba(6, 182, 212, .08);
}

.badge-suspended {

    color: #ef4444;

    background:
        rgba(239, 68, 68, .08);
}

.action-form {

    display: inline-block;

    margin: 0 3px 3px 0;
}

.action-btn {

    padding:
        6px 9px;

    border-radius: 5px;

    border:
        1px solid var(--border);

    background: var(--panel);

    color: var(--muted);

    cursor: pointer;

    font-size: .65rem;

    font-weight: 700;
}

.action-btn:hover {

    border-color:
        #a855f7;

    color: var(--text);
}

/* RESPONSIVE */

@media(max-width: 1100px) {

    .stats-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .main-grid {

        grid-template-columns:
            1fr;
    }
}

@media(max-width: 650px) {

    .container {

        padding: 18px;
    }

    .topbar {

        align-items: flex-start;

        flex-direction: column;
    }

    .stats-grid {

        grid-template-columns:
            1fr;
    }

    .brand {

        align-items: flex-start;

        flex-direction: column;
    }

    .logo {

        width: 140px;
    }
}

</style>

</head>

<body>

<?php include __DIR__ . '/../preloader.php'; ?>

<div class="container">

<header class="topbar">

    <div class="brand">

        <div class="logo">
            ScholarUg
        </div>

        <div class="brand-info">

            <h1>
                Super Admin Control Center
            </h1>

            <p>
                Welcome back,
                <?= htmlspecialchars(
                    $dev_user,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>

        </div>

    </div>

    <a
        href="messages.php"
        class="logout"
        style="color:#06b6d4;border-color:rgba(6,182,212,.25);margin-right:10px;"
    >
        Messages
    </a>

    <a
        href="logout.php"
        class="logout"
    >
        Logout
    </a>

</header>


<?php if ($msg !== ''): ?>

<div class="alert <?= $msg_type ?>">

    <?= htmlspecialchars(
        $msg,
        ENT_QUOTES,
        'UTF-8'
    ) ?>

</div>

<?php endif; ?>


<!-- =========================================================
     PLATFORM STATISTICS
========================================================= -->

<div class="stats-grid">

    <div class="stat-card">

        <div class="stat-label">
            Total Schools
        </div>

        <div class="stat-number">
            <?= number_format($total_schools) ?>
        </div>

        <div class="stat-description">
            All registered schools
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-label">
            Active Schools
        </div>

        <div class="stat-number">
            <?= number_format($active_schools) ?>
        </div>

        <div class="stat-description">
            Currently operational
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-label">
            Suspended Schools
        </div>

        <div class="stat-number">
            <?= number_format($suspended_schools) ?>
        </div>

        <div class="stat-description">
            Currently inactive
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-label">
            Payment Pending
        </div>

        <div class="stat-number">
            <?= number_format($pending_payment_schools) ?>
        </div>

        <div class="stat-description">
            Schools requiring attention
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-label">
            Total Learners
        </div>

        <div class="stat-number">
            <?= number_format($total_learners) ?>
        </div>

        <div class="stat-description">
            Across all schools
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-label">
            Total Teachers
        </div>

        <div class="stat-number">
            <?= number_format($total_teachers) ?>
        </div>

        <div class="stat-description">
            Across all schools
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-label">
            Total Staff
        </div>

        <div class="stat-number">
            <?= number_format($total_staff) ?>
        </div>

        <div class="stat-description">
            Registered staff
        </div>

    </div>


    <div class="stat-card">

        <div class="stat-label">
            Total Users
        </div>

        <div class="stat-number">
            <?= number_format($total_users) ?>
        </div>

        <div class="stat-description">
            Platform accounts
        </div>

    </div>

</div>


<!-- =========================================================
     MANAGEMENT / ALERTS
========================================================= -->

<div class="main-grid">


    <!-- REGISTER SCHOOL -->

    <div class="card">

        <h2>
            Register New School
        </h2>

        <form
            method="POST"
            action=""
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrf_token,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <div class="form-group">

                <label>
                    School Name
                </label>

                <input
                    type="text"
                    name="school_name"
                    class="form-control"
                    placeholder="Enter school name"
                    required
                >

            </div>

            <div class="form-group">

                <label>
                    School Type
                </label>

                <select
                    name="school_type"
                    class="form-control"
                    required
                >

                    <option value="" disabled selected>
                        Select school type
                    </option>

                    <option value="Primary">
                        Primary (incl. Pre-Primary)
                    </option>

                    <option value="Secondary">
                        Secondary (O-Level / A-Level)
                    </option>

                </select>

            </div>

            <button
                type="submit"
                name="provision_school"
                class="btn btn-cyan"
            >
                Register School
            </button>

        </form>

    </div>


    <!-- PAYMENT ALERTS -->

    <div class="card">

        <h2>
            Platform Alerts
        </h2>

        <div class="alert-list">

            <?php if ($pending_payment_schools > 0): ?>

            <div class="alert-item alert-warning">

                <span>
                    <?= number_format(
                        $pending_payment_schools
                    ) ?>

                    school(s) have pending payment.
                </span>

                <strong>
                    REVIEW
                </strong>

            </div>

            <?php endif; ?>


            <?php if ($suspended_schools > 0): ?>

            <div class="alert-item alert-danger">

                <span>
                    <?= number_format(
                        $suspended_schools
                    ) ?>

                    school(s) are currently suspended.
                </span>

                <strong>
                    REVIEW
                </strong>

            </div>

            <?php endif; ?>


            <?php if (
                $pending_payment_schools === 0 &&
                $suspended_schools === 0
            ): ?>

            <div class="alert-item alert-success">

                <span>
                    No immediate school alerts.
                </span>

                <strong>
                    OK
                </strong>

            </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- =========================================================
     RECENT SCHOOLS
========================================================= -->

<div class="table-card">

    <h2>
        Recently Registered Schools
    </h2>

    <div class="table-wrapper">

    <table>

        <thead>

        <tr>

            <th>
                School
            </th>

            <th>
                Registered
            </th>

            <th>
                Users
            </th>

            <th>
                Payment
            </th>

            <th>
                Status
            </th>

        </tr>

        </thead>

        <tbody>

        <?php if (!$recent_schools): ?>

        <tr>

            <td colspan="5">

                No schools have been registered yet.

            </td>

        </tr>

        <?php else: ?>

        <?php foreach (
            $recent_schools
            as $school
        ): ?>

        <?php

        $school_id =
            (int) $school['id'];

        $school_users =
            $school_user_counts[
                $school_id
            ] ?? 0;

        $is_active =
            (int) (
                $school['is_active']
                ?? 0
            ) === 1;

        $is_paid =
            ($school['payment_status']
            ?? '') === 'paid';

        ?>

        <tr>

            <td>

                <div class="school-code">

                    <?= htmlspecialchars(
                        $school['school_code']
                        ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

                <div class="school-name">

                    <?= htmlspecialchars(
                        $school['school_name']
                        ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            </td>


            <td>

                <?= format_dashboard_date(
                    $school['created_at']
                    ?? null
                ) ?>

            </td>


            <td>

                <?= number_format(
                    $school_users
                ) ?>

            </td>


            <td>

                <span class="badge
                    <?= $is_paid
                        ? 'badge-paid'
                        : 'badge-pending'
                    ?>">

                    <?= $is_paid
                        ? 'Paid'
                        : 'Pending'
                    ?>

                </span>

            </td>


            <td>

                <span class="badge
                    <?= $is_active
                        ? 'badge-active'
                        : 'badge-suspended'
                    ?>">

                    <?= $is_active
                        ? 'Active'
                        : 'Suspended'
                    ?>

                </span>

            </td>

        </tr>

        <?php endforeach; ?>

        <?php endif; ?>

        </tbody>

    </table>

    </div>

</div>


<br>


<!-- =========================================================
     ALL SCHOOLS
========================================================= -->

<div class="table-card">

    <h2>
        School Network
    </h2>

    <div class="table-wrapper">

    <table>

        <thead>

        <tr>

            <th>
                School
            </th>

            <th>
                Users
            </th>

            <th>
                Registered
            </th>

            <th>
                Payment
            </th>

            <th>
                Status
            </th>

            <th>
                Operations
            </th>

        </tr>

        </thead>

        <tbody>

        <?php foreach (
            $recent_schools
            as $school
        ): ?>

        <?php

        $school_id =
            (int) $school['id'];

        $school_users =
            $school_user_counts[
                $school_id
            ] ?? 0;

        $is_active =
            (int) (
                $school['is_active']
                ?? 0
            ) === 1;

        $is_paid =
            ($school['payment_status']
            ?? '') === 'paid';

        ?>

        <tr>

            <td>

                <div class="school-code">

                    <?= htmlspecialchars(
                        $school['school_code']
                        ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

                <div class="school-name">

                    <?= htmlspecialchars(
                        $school['school_name']
                        ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            </td>


            <td>

                <?= number_format(
                    $school_users
                ) ?>

            </td>


            <td>

                <?= format_dashboard_date(
                    $school['created_at']
                    ?? null
                ) ?>

            </td>


            <td>

                <form
                    method="POST"
                    class="action-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $csrf_token,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="school_id"
                        value="<?= $school_id ?>"
                    >

                    <button
                        type="submit"
                        name="toggle_payment"
                        class="action-btn"
                    >

                        <?= $is_paid
                            ? 'Paid'
                            : 'Pending'
                        ?>

                    </button>

                </form>

            </td>


            <td>

                <span class="badge
                    <?= $is_active
                        ? 'badge-active'
                        : 'badge-suspended'
                    ?>">

                    <?= $is_active
                        ? 'Active'
                        : 'Suspended'
                    ?>

                </span>

            </td>


            <td>

                <form
                    method="POST"
                    class="action-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $csrf_token,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="school_id"
                        value="<?= $school_id ?>"
                    >

                    <button
                        type="submit"
                        name="toggle_active"
                        class="action-btn"
                    >

                        <?= $is_active
                            ? 'Suspend'
                            : 'Activate'
                        ?>

                    </button>

                </form>


                <form
                    method="POST"
                    class="action-form"
                    onsubmit="
                        return confirm(
                            'Archive this school from active operations?'
                        );
                    "
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $csrf_token,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="school_id"
                        value="<?= $school_id ?>"
                    >

                    <button
                        type="submit"
                        name="archive_school"
                        class="action-btn"
                    >

                        Archive

                    </button>

                </form>


                <a href="delete_school.php?id=<?= $school_id ?>">

                    <button
                        type="button"
                        class="action-btn"
                        style="background:#450a0a;border-color:#7f1d1d;color:#fca5a5;"
                    >

                        Delete

                    </button>

                </a>

            </td>

        </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    </div>

</div>

</div>

</body>

</html>
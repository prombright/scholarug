<?php
// students.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. TOP-LEVEL CSV TEMPLATE DOWNLOAD (Must run before any HTML output)
if (isset($_GET['download_template']) && $_GET['download_template'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_import_template.csv');
    
    // Class Name here must match a class exactly as it appears in Classes
    // (e.g. "S.1", not "Senior 1") -- rows whose class doesn't match one
    // already created for this school are skipped on import, not guessed.
    // No Level Type column -- it's derived automatically from Class Name
    // on import (S.1-S.4 = O-Level, S.5-S.6 = A-Level), so there's nothing
    // here that can be typed inconsistently with the class chosen.
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Full Name', 'Gender', 'Class Name']);
    fputcsv($output, ['John Mukasa', 'Male', 'S.1']);
    fputcsv($output, ['Mary Akello', 'Female', 'S.5']);
    fclose($output);
    exit();
}

// Flexible Path Resolution for Config & Database Connections
$base_dir = __DIR__;

if (file_exists($base_dir . '/config.php')) {
    require_once $base_dir . '/config.php';
} elseif (file_exists($base_dir . '/../config.php')) {
    require_once $base_dir . '/../config.php';
} elseif (file_exists($base_dir . '/../../config.php')) {
    require_once $base_dir . '/../../config.php';
}

if (file_exists($base_dir . '/db.php')) {
    require_once $base_dir . '/db.php';
} elseif (file_exists($base_dir . '/../db.php')) {
    require_once $base_dir . '/../db.php';
} elseif (file_exists($base_dir . '/../../db.php')) {
    require_once $base_dir . '/../../db.php';
}

if (file_exists($base_dir . '/auth_guard.php')) {
    require_once $base_dir . '/auth_guard.php';
} elseif (file_exists($base_dir . '/../auth_guard.php')) {
    require_once $base_dir . '/../auth_guard.php';
} elseif (file_exists($base_dir . '/../../auth_guard.php')) {
    require_once $base_dir . '/../../auth_guard.php';
}

if (file_exists($base_dir . '/../_subject_helpers.php')) {
    require_once $base_dir . '/../_subject_helpers.php';
}

// Registers/bulk-imports students and mints student portal logins -- had
// no role check at all, meaning any logged-in user of any role could open
// it directly by URL.
require_role(['school_admin']);

$ACTIVE_NAV = 'students';

if (file_exists($base_dir . '/_admin_shell.php')) {
    require_once $base_dir . '/_admin_shell.php';
} elseif (file_exists($base_dir . '/../_admin_shell.php')) {
    require_once $base_dir . '/../_admin_shell.php';
}

if (!function_exists('safe_text')) {
    function safe_text($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Secondary classes are named S.1-S.6 (see school_admin/classes.php's
// $LEVEL_CLASSES) -- S.1-S.4 is O-Level, S.5-S.6 is A-Level. Used to filter
// the "Register New Student" Class dropdown down to just the classes that
// match whichever Level Type is picked, instead of showing every class in
// the school (including the wrong level) at once.
if (!function_exists('scholar_class_level_type')) {
    function scholar_class_level_type(string $className): string {
        if (preg_match('/([1-9][0-9]*)/', $className, $m)) {
            return ((int) $m[1] >= 5) ? 'A-Level' : 'O-Level';
        }
        return '';
    }
}

if (!function_exists('scholar_generate_temp_code')) {
    // Excludes O/0/I/1 -- easy to misread on a printed sheet or misread
    // aloud by a teacher.
    function scholar_generate_temp_code(int $length = 6): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }
}

// scholar_generate_student_no() lives in auth_guard.php (already required
// above) -- shared with the one-off _setup/backfill_missing_student_numbers.php
// script, which can't load this whole page just for one helper function.

if (!function_exists('scholar_create_student_login')) {
    /**
     * Creates a portal login for a student that doesn't have one yet. Used
     * both by the manual "Create Login" button (legacy students) and
     * automatically right after a student is added/imported.
     */
    function scholar_create_student_login(PDO $pdo, int $student_id, int $school_id): array {
        $stu_stmt = $pdo->prepare("SELECT id, full_name, student_no FROM students WHERE id = ? AND school_id = ?");
        $stu_stmt->execute([$student_id, $school_id]);
        $student_row = $stu_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student_row) {
            return ['ok' => false, 'error' => 'Student not found.'];
        }

        $existing_stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ? AND school_id = ?");
        $existing_stmt->execute([$student_id, $school_id]);
        if ($existing_stmt->fetch()) {
            return ['ok' => false, 'error' => 'This student already has a login.'];
        }

        // One generated code serves as BOTH username and password -- easier
        // for younger students to remember and for a teacher to read out
        // loud than two separate strings. is_temp_password=1 still forces
        // them to set a real password on first login (login.php redirects
        // to force_password_reset.php). Deliberately derived from
        // student_no (not random) so it stays re-printable later via
        // print_student_credentials.php for as long as it's still active.
        $code = $student_row['student_no'] ?: ('STU' . str_pad((string) $student_id, 4, '0', STR_PAD_LEFT));
        $hash = password_hash($code, PASSWORD_BCRYPT);

        $create_stmt = $pdo->prepare("
            INSERT INTO users (username, password, role, student_id, school_id, is_temp_password, temp_password_plain, account_status)
            VALUES (?, ?, 'student', ?, ?, 1, ?, 'active')
        ");

        try {
            $create_stmt->execute([$code, $hash, $student_id, $school_id, $code]);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Failed to create login: username may already be taken.'];
        }

        return ['ok' => true, 'full_name' => $student_row['full_name'], 'username' => $code, 'password' => $code];
    }
}

$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id) {
    header("Location: ../login.php");
    exit();
}

// Fetch active school details
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

$message = '';
$error = '';
$skipped_rows = [];

// ==========================================
// HANDLE ACTIONS (Single Add & Bulk Import)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. Single Student Registration
    if ($_POST['action'] === 'add_student') {
        if (function_exists('require_active_subscription')) {
            require_active_subscription();
        }

        $full_name  = trim($_POST['full_name'] ?? '');
        $gender     = trim($_POST['gender'] ?? '');
        $class_id   = trim($_POST['class_id'] ?? '');
        $level_type = trim($_POST['level_type'] ?? 'O-Level');

        if (empty($full_name) || empty($gender) || empty($class_id)) {
            $error = "Please fill in all required fields.";
        } else {
            $class_stmt = $pdo->prepare("SELECT class_name FROM classes WHERE id = ? AND school_id = ?");
            $class_stmt->execute([$class_id, $school_id]);
            $class_data = $class_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$class_data) {
                $error = "Invalid class selected.";
            } else {
                $class_name = $class_data['class_name'];

                // Defensive backstop: classes.php auto-adopts these on
                // creation now, but a class created before that shipped
                // (or created some other way) still gets covered here,
                // before the student who'd need them is even saved.
                if (function_exists('scholar_ensure_compulsory_subjects')) {
                    scholar_ensure_compulsory_subjects($pdo, $school_id, $class_name);
                }

                $student_no = scholar_generate_student_no($pdo);
                // `gender` is a VIRTUAL GENERATED column derived from `sex` —
                // it can only be read, never written. Write to `sex` instead.
                $insert = $pdo->prepare("
                    INSERT INTO students (school_id, full_name, sex, class_id, class_name, level_type, student_no)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                try {
                    $insert->execute([$school_id, $full_name, $gender, $class_id, $class_name, $level_type, $student_no]);
                    $new_student_id = (int) $pdo->lastInsertId();
                    $login_result = scholar_create_student_login($pdo, $new_student_id, $school_id);
                    if ($login_result['ok']) {
                        $message = "Student registered — portal login created (Access Code: {$login_result['username']}, used as both username and password).";
                    } else {
                        // The student record itself is safely saved either
                        // way -- login creation failing is a soft failure,
                        // the "Create Login" button in the table below
                        // still works as a manual retry.
                        $message = "Student registered successfully! (Portal login not created: {$login_result['error']})";
                    }
                } catch (PDOException $e) {
                    $error = "Failed to register student.";
                }
            }
        }
    }

    // 2. Bulk CSV Import
    if ($_POST['action'] === 'import_csv' && isset($_FILES['csv_file'])) {
        if (function_exists('require_active_subscription')) {
            require_active_subscription();
        }

        $file = $_FILES['csv_file']['tmp_name'];
        
        if (empty($file) || !is_uploaded_file($file)) {
            $error = "Please select a valid CSV file to upload.";
        } else {
            $handle = fopen($file, "r");
            $header = fgetcsv($handle, 1000, ","); // Skip header line

            // Resolve every row's class against this school's real classes
            // in PHP (via the shared normalizer) instead of a raw SQL LIKE
            // against unnormalized text -- a raw LIKE let "Senior 1" and
            // "S1" both silently miss a class actually named "S.1", and
            // previously inserted the free-text name anyway even then,
            // which is exactly how the class-name inconsistency spread.
            $classes_lookup_stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ?");
            $classes_lookup_stmt->execute([$school_id]);
            $class_lookup = [];
            foreach ($classes_lookup_stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $class_lookup[scholar_normalize_class_name($c['class_name'])] = $c;
            }

            $imported_count = 0;
            $login_created_count = 0;
            $skipped_rows = [];
            $row_num = 1; // header already consumed
            $subjects_ensured_for_class = []; // class_id => true, so a 200-row CSV against 4 classes doesn't repeat the check 200 times
            // See note above: write to `sex`, not the generated `gender` column.
            $insert_stmt = $pdo->prepare("
                INSERT INTO students (school_id, full_name, sex, class_id, class_name, level_type, student_no)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $row_num++;
                $csv_name       = trim($data[0] ?? '');
                $csv_gender     = trim($data[1] ?? 'Male');
                $csv_class_name = trim($data[2] ?? '');

                if (empty($csv_name)) {
                    continue;
                }

                $match = $class_lookup[scholar_normalize_class_name($csv_class_name)] ?? null;
                if (!$match) {
                    $skipped_rows[] = "Row {$row_num}: class \"{$csv_class_name}\" not found for {$csv_name} — add it via Classes first, or check the spelling.";
                    continue;
                }

                // Level is derived from the matched class, not typed in the
                // CSV -- same rule (S.1-S.4 = O-Level, S.5-S.6 = A-Level) the
                // "Register New Student" form already uses via
                // scholar_class_level_type(), so a row can no longer import
                // with a class/level mismatch (e.g. "S.1" + "A-Level" typed
                // by mistake). Classes that don't match the S.<number>
                // pattern (e.g. a primary school's "Baby Class"/"P.3") fall
                // back to 'Primary', mirroring the single-add form's own
                // hardcoded Primary path.
                $csv_level = scholar_class_level_type($match['class_name']) ?: 'Primary';

                if (function_exists('scholar_ensure_compulsory_subjects') && !isset($subjects_ensured_for_class[$match['id']])) {
                    scholar_ensure_compulsory_subjects($pdo, $school_id, $match['class_name']);
                    $subjects_ensured_for_class[$match['id']] = true;
                }

                if ($insert_stmt->execute([$school_id, $csv_name, $csv_gender, $match['id'], $match['class_name'], $csv_level, scholar_generate_student_no($pdo)])) {
                    $imported_count++;
                    $new_student_id = (int) $pdo->lastInsertId();
                    $login_result = scholar_create_student_login($pdo, $new_student_id, $school_id);
                    if ($login_result['ok']) {
                        $login_created_count++;
                    }
                }
            }
            fclose($handle);
            $message = "Successfully imported {$imported_count} students and created {$login_created_count} portal login(s)." . (!empty($skipped_rows) ? ' ' . count($skipped_rows) . ' row(s) were skipped.' : ' Use "Print Class Credentials" below to hand them out.');
        }
    }

    // 3. Create a portal login for a student (username + temp password) --
    // now mostly a fallback for students added before logins became
    // automatic; see scholar_create_student_login() above.
    if ($_POST['action'] === 'create_student_login') {
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $result = scholar_create_student_login($pdo, $student_id, $school_id);

        if ($result['ok']) {
            $message = "Login created for {$result['full_name']} — Access Code: {$result['username']} (used as both username and password). Share this with the student now; they'll set their own password on first login.";
        } else {
            $error = $result['error'];
        }
    }

    // 4. Regenerate a student's password. Only reachable by school_admin
    // (this whole file is gated by require_role(['school_admin']) above).
    // Produces a genuinely new random temp password -- not the reproducible
    // student_no-derived code -- so anyone who knew the old one loses
    // access, and clears any pending reset_requested flag from a class
    // teacher (see teacher_class_logins.php).
    if ($_POST['action'] === 'regenerate_student_password') {
        $student_id = (int) ($_POST['student_id'] ?? 0);

        $user_stmt = $pdo->prepare("
            SELECT u.id, s.full_name
            FROM users u
            JOIN students s ON s.id = u.student_id
            WHERE u.student_id = ? AND u.school_id = ? AND u.role = 'student'
        ");
        $user_stmt->execute([$student_id, $school_id]);
        $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user_row) {
            $error = "This student doesn't have a portal login yet.";
        } else {
            $new_code = scholar_generate_temp_code();
            $hash = password_hash($new_code, PASSWORD_BCRYPT);

            $upd = $pdo->prepare("
                UPDATE users
                SET password = ?, is_temp_password = 1, temp_password_plain = ?, reset_requested = 0
                WHERE id = ? AND school_id = ?
            ");
            $upd->execute([$hash, $new_code, $user_row['id'], $school_id]);

            $message = "Password reset for {$user_row['full_name']} — New Access Code: {$new_code}. Share this with the student now; they'll set their own password on first login.";
        }
    }
}

// Which students already have a portal login? (drives the button/state per row)
$student_logins_stmt = $pdo->prepare("SELECT student_id, username, reset_requested FROM users WHERE school_id = ? AND role = 'student' AND student_id IS NOT NULL");
$student_logins_stmt->execute([$school_id]);
$student_logins = [];
foreach ($student_logins_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $student_logins[(int) $row['student_id']] = $row;
}

// Search/filter are handled client-side (Vue) against the full list below —
// no round trip to the server just to narrow a few hundred rows. `q`/`class_id`
// in the URL still seed the initial filter state, so a bookmarked or shared
// filtered link still opens pre-filtered.
$search_query = trim($_GET['q'] ?? '');
$filter_class = trim($_GET['class_id'] ?? '');

$students_stmt = $pdo->prepare("
    SELECT s.*, c.class_name
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE s.school_id = ?
    ORDER BY s.id DESC
");
$students_stmt->execute([$school_id]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fold each student's login state + report-card link into the row itself,
// since that's exactly the shape the Vue table below needs.
$report_term = current_term();
$report_year = current_year();
foreach ($students as &$row) {
    $login = $student_logins[(int) $row['id']] ?? null;
    $row['login_username'] = $login['username'] ?? null;
    $row['reset_requested'] = $login ? (bool) $login['reset_requested'] : false;
    $row['edit_url'] = 'edit_student.php?id=' . (int) $row['id'];
    $row['report_url'] = '../generate_report.php?student_id=' . (int) $row['id'] . '&term=' . urlencode($report_term) . '&year=' . urlencode($report_year);
    $row['subjects_url'] = 'student_subjects.php?student_id=' . (int) $row['id'];
}
unset($row);

// Fetch classes for dropdown filter
$classes_stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Student Stats
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ?");
$total_stmt->execute([$school_id]);
$total_students = $total_stmt->fetchColumn();

$male_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND (gender = 'Male' OR gender = 'M' OR sex = 'Male')");
$male_stmt->execute([$school_id]);
$total_male = $male_stmt->fetchColumn();

$female_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND (gender = 'Female' OR gender = 'F' OR sex = 'Female')");
$female_stmt->execute([$school_id]);
$total_female = $female_stmt->fetchColumn();
?>

<style>
.page-header{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:22px;}
.page-header h1{font-size:1.4rem;margin:0 0 4px;}
.page-header .sub{color:var(--muted);font-size:0.85rem;}
.header-actions{display:flex;flex-wrap:wrap;gap:10px;}
.btn{display:inline-flex;align-items:center;gap:6px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-size:0.85rem;text-decoration:none;}
.btn-ghost{background:transparent;color:var(--text);border:1px solid var(--border);}
.btn-sm{padding:6px 12px;font-size:0.78rem;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
.stat-card .n{font-size:1.9rem;font-weight:700;}
.stat-card .n.cyan{color:var(--cyan);}
.stat-card .n.green{color:var(--green);}
.stat-card .n.danger{color:var(--danger);}
.stat-card .label{color:var(--muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}
details.section-toggle{background:var(--panel);border:1px solid var(--border);border-radius:10px;margin-bottom:16px;}
details.section-toggle summary{cursor:pointer;padding:16px 20px;font-weight:700;font-size:0.95rem;list-style:none;}
details.section-toggle summary::-webkit-details-marker{display:none;}
details.section-toggle summary::before{content:"+ ";color:var(--cyan);}
details.section-toggle[open] summary::before{content:"– ";}
details.section-toggle .form-body{padding:0 20px 20px;border-top:1px solid var(--border);padding-top:16px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
input,select{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
.row{display:flex;gap:12px;flex-wrap:wrap;}
.row > div{flex:1;min-width:180px;}
.hint{color:var(--muted);font-size:0.8rem;margin-top:10px;}
.filter-bar{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.filter-bar .row > div{margin-bottom:0;}
table{width:100%;border-collapse:collapse;font-size:0.88rem;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
table{display:block;overflow-x:auto;}
th,td{text-align:left;padding:12px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;}
tr:last-child td{border-bottom:none;}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;background:rgba(0,168,168,0.1);color:var(--cyan);}
.pill.green{background:rgba(16,185,129,0.12);color:var(--green);}
.pill.muted{background:rgba(100,116,139,0.15);color:var(--muted);}
.actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;}
.empty-row{text-align:center;color:var(--muted);padding:30px !important;}
</style>

<main class="main-content">
<div class="page-inner">

<div class="page-header">
    <div>
        <h1>Student Management</h1>
        <div class="sub"><?= safe_text($school['school_name'] ?? 'School Admin Portal'); ?></div>
    </div>
    <div class="header-actions">
        <a href="students.php?download_template=csv" class="btn btn-ghost btn-sm">Download CSV Template</a>
        <a href="print_student_credentials.php" class="btn btn-ghost btn-sm">Print Class Credentials</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert success"><?= safe_text($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert error"><?= safe_text($error); ?></div>
<?php endif; ?>

<?php if (!empty($skipped_rows)): ?>
    <div class="alert error">
        <strong>Some rows were skipped:</strong>
        <ul style="margin:8px 0 0;padding-left:20px;">
            <?php foreach ($skipped_rows as $row_msg): ?>
                <li><?= safe_text($row_msg); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="n cyan"><?= number_format($total_students); ?></div>
        <div class="label">Total Students</div>
    </div>
    <div class="stat-card">
        <div class="n"><?= number_format($total_male); ?></div>
        <div class="label">Male Students</div>
    </div>
    <div class="stat-card">
        <div class="n"><?= number_format($total_female); ?></div>
        <div class="label">Female Students</div>
    </div>
</div>

<details class="section-toggle">
    <summary>Register New Student</summary>
    <div class="form-body">
        <form method="POST" action="students.php">
            <input type="hidden" name="action" value="add_student">
            <div class="row">
                <div>
                    <label>Full Name</label>
                    <input type="text" name="full_name" required placeholder="e.g. John Doe">
                </div>
                <div>
                    <label>Gender</label>
                    <select name="gender" required>
                        <option value="">Select Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <?php if (($school['school_type'] ?? 'Secondary') === 'Primary'): ?>
                    <input type="hidden" name="level_type" value="Primary">
                <?php else: ?>
                <div>
                    <label>Level Type</label>
                    <select name="level_type" id="reg_level_type" onchange="scholarFilterClassesByLevel()">
                        <option value="O-Level">O-Level (S.1 - S.4)</option>
                        <option value="A-Level">A-Level (S.5 - S.6)</option>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label>Class</label>
                    <select name="class_id" id="reg_class_id" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id']; ?>" data-level="<?= safe_text(scholar_class_level_type($c['class_name'])) ?>"><?= safe_text($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn" style="margin-top:16px;">Save Student</button>
            <?php if (($school['school_type'] ?? 'Secondary') !== 'Primary'): ?>
            <script>
                // Only Secondary schools have an O-Level/A-Level split -- Primary
                // classes have no `data-level` at all (scholar_class_level_type()
                // only recognizes S.1-S.6), so this never runs for them.
                function scholarFilterClassesByLevel() {
                    var level = document.getElementById('reg_level_type').value;
                    var classSelect = document.getElementById('reg_class_id');
                    var options = classSelect.querySelectorAll('option[data-level]');
                    var currentStillValid = false;
                    options.forEach(function (opt) {
                        var matches = opt.getAttribute('data-level') === level;
                        opt.hidden = !matches;
                        if (matches && opt.selected) currentStillValid = true;
                    });
                    if (!currentStillValid) classSelect.value = '';
                }
                scholarFilterClassesByLevel();
            </script>
            <?php endif; ?>
        </form>
    </div>
</details>

<details class="section-toggle">
    <summary>Bulk CSV Import</summary>
    <div class="form-body">
        <form method="POST" action="students.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_csv">
            <p class="hint" style="margin-top:0;">Upload a CSV file to register multiple students at once. Format: <strong>Full Name, Gender, Class Name</strong> — O-Level/A-Level is set automatically from the class (S.1-S.4 vs S.5-S.6). Use the "Download CSV Template" button above for a ready-made example.</p>
            <label>Select CSV File</label>
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit" class="btn" style="margin-top:16px;">Upload &amp; Import</button>
        </form>
    </div>
</details>

<div id="studentsApp">
    <div class="filter-bar">
        <div class="row" style="flex:2;min-width:220px;">
            <div>
                <label style="margin-top:0;">Search</label>
                <input type="text" v-model="search" placeholder="Search by student full name...">
            </div>
        </div>
        <div class="row" style="flex:1;min-width:160px;">
            <div>
                <label style="margin-top:0;">Class</label>
                <select v-model="classFilter">
                    <option value="">All Classes</option>
                    <option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.class_name }}</option>
                </select>
            </div>
        </div>
        <div class="hint" style="align-self:center;margin-top:0;">{{ filtered.length }} of {{ students.length }} students</div>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Full Name</th>
            <th>Gender</th>
            <th>Class</th>
            <th>Level Type</th>
            <th style="text-align:right;">Actions</th>
        </tr>
        <tr v-for="row in filtered" :key="row.id">
            <td class="hint">#{{ row.id }}</td>
            <td><strong>{{ row.full_name }}</strong></td>
            <td><span class="pill muted">{{ row.gender || row.sex || 'N/A' }}</span></td>
            <td>{{ row.class_name || 'Unassigned' }}</td>
            <td><span class="pill">{{ row.level_type || '—' }}</span></td>
            <td>
                <div class="actions">
                    <a :href="row.edit_url" class="btn btn-ghost btn-sm">Edit</a>
                    <a :href="row.report_url" class="btn btn-ghost btn-sm" target="_blank">Report Card</a>
                    <a :href="row.subjects_url" class="btn btn-ghost btn-sm">Subjects</a>
                    <span v-if="row.login_username" class="pill green" title="Portal username">{{ row.login_username }}</span>
                    <span v-if="row.reset_requested" class="pill" style="background:rgba(245,158,11,0.15);color:#f59e0b;" title="A class teacher requested a password reset for this student">Reset requested</span>
                    <form v-if="row.login_username" method="POST" action="students.php" style="display:inline;" onsubmit="return confirm('Generate a new password for this student? Their old password will stop working immediately.');">
                        <input type="hidden" name="action" value="regenerate_student_password">
                        <input type="hidden" name="student_id" :value="row.id">
                        <button type="submit" class="btn btn-ghost btn-sm">Regenerate Password</button>
                    </form>
                    <form v-else method="POST" action="students.php" style="display:inline;" onsubmit="return confirm('Create a portal login for this student?');">
                        <input type="hidden" name="action" value="create_student_login">
                        <input type="hidden" name="student_id" :value="row.id">
                        <button type="submit" class="btn btn-ghost btn-sm">Create Login</button>
                    </form>
                </div>
            </td>
        </tr>
        <tr v-if="filtered.length === 0">
            <td colspan="6" class="empty-row">No student records found.</td>
        </tr>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
<script>
Vue.createApp({
    data() {
        return {
            students: <?= json_encode($students, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            classes: <?= json_encode($classes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            search: <?= json_encode($search_query, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            classFilter: <?= json_encode($filter_class, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        };
    },
    computed: {
        filtered() {
            const q = this.search.trim().toLowerCase();
            return this.students.filter(s => {
                if (this.classFilter && String(s.class_id) !== this.classFilter) return false;
                if (q && !s.full_name.toLowerCase().includes(q)) return false;
                return true;
            });
        },
    },
}).mount('#studentsApp');
</script>

</div><!-- /.page-inner -->
</main>
</div><!-- /.app-shell (opened in _admin_shell.php) -->
</body>
</html>
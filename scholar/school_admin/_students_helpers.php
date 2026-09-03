<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT MANAGEMENT: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/students.php so the classic page and the JSON
| endpoint (api/admin/students.php) mint logins/passwords the exact same
| way. CSV import stays a classic multipart form POST in students.php
| itself (file upload, not JSON-shaped) -- see api/admin/students.php's
| header comment.
|--------------------------------------------------------------------------
*/

/**
 * Secondary classes are named S.1-S.6 -- S.1-S.4 is O-Level, S.5-S.6 is
 * A-Level. Classes that don't match the S.<number> pattern (e.g. a primary
 * school's "Baby Class"/"P.3") return '' -- callers fall back to 'Primary'.
 */
function admin_student_level_type(string $className): string
{
    // Anchored to an "S" prefix -- matching any digit in the class name
    // (the previous version of this regex) misread "P.1" as if it were
    // "S.1" and tagged Primary students O-Level/A-Level, which is exactly
    // what was leaking onto the primary report card badge.
    if (preg_match('/^S\.?\s*([1-9][0-9]*)/i', $className, $m)) {
        return ((int) $m[1] >= 5) ? 'A-Level' : 'O-Level';
    }
    return '';
}

/** Excludes O/0/I/1 -- easy to misread on a printed sheet or misread aloud by a teacher. */
function admin_generate_temp_code(int $length = 6): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

/**
 * Creates a portal login for a student that doesn't have one yet. One
 * generated code serves as BOTH username and password, derived from
 * student_no (not random) so it stays re-printable later via
 * print_student_credentials.php for as long as it's still active.
 *
 * @return array{ok:bool,error?:string,full_name?:string,username?:string,password?:string}
 */
function admin_create_student_login(PDO $pdo, int $studentId, int $schoolId): array
{
    $stu_stmt = $pdo->prepare('SELECT id, full_name, student_no FROM students WHERE id = ? AND school_id = ?');
    $stu_stmt->execute([$studentId, $schoolId]);
    $student_row = $stu_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student_row) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }

    $existing_stmt = $pdo->prepare('SELECT id FROM users WHERE student_id = ? AND school_id = ?');
    $existing_stmt->execute([$studentId, $schoolId]);
    if ($existing_stmt->fetch()) {
        return ['ok' => false, 'error' => 'This student already has a login.'];
    }

    $code = $student_row['student_no'] ?: ('STU' . str_pad((string) $studentId, 4, '0', STR_PAD_LEFT));
    $hash = password_hash($code, PASSWORD_BCRYPT);

    $create_stmt = $pdo->prepare("
        INSERT INTO users (username, password, role, student_id, school_id, is_temp_password, temp_password_plain, account_status)
        VALUES (?, ?, 'student', ?, ?, 1, ?, 'active')
    ");

    try {
        $create_stmt->execute([$code, $hash, $studentId, $schoolId, $code]);
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Failed to create login: username may already be taken.'];
    }

    return ['ok' => true, 'full_name' => $student_row['full_name'], 'username' => $code, 'password' => $code];
}

/** @return array{ok:bool,message:string} */
function admin_students_add(PDO $pdo, int $schoolId, string $fullName, string $gender, int $classId, string $levelType): array
{
    if ($fullName === '' || $gender === '' || $classId <= 0) {
        return ['ok' => false, 'message' => 'Please fill in all required fields.'];
    }

    $class_stmt = $pdo->prepare('SELECT class_name FROM classes WHERE id = ? AND school_id = ?');
    $class_stmt->execute([$classId, $schoolId]);
    $class_data = $class_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class_data) {
        return ['ok' => false, 'message' => 'Invalid class selected.'];
    }
    $class_name = $class_data['class_name'];

    if (function_exists('scholar_ensure_compulsory_subjects')) {
        scholar_ensure_compulsory_subjects($pdo, $schoolId, $class_name);
    }

    $student_no = scholar_generate_student_no($pdo);
    $insert = $pdo->prepare('
        INSERT INTO students (school_id, full_name, sex, class_id, class_name, level_type, student_no)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    try {
        $insert->execute([$schoolId, $fullName, $gender, $classId, $class_name, $levelType, $student_no]);
    } catch (PDOException $e) {
        return ['ok' => false, 'message' => 'Failed to register student.'];
    }

    $new_student_id = (int) $pdo->lastInsertId();
    $login_result = admin_create_student_login($pdo, $new_student_id, $schoolId);
    if ($login_result['ok']) {
        return ['ok' => true, 'message' => "Student registered — portal login created (Access Code: {$login_result['username']}, used as both username and password)."];
    }
    return ['ok' => true, 'message' => "Student registered successfully! (Portal login not created: {$login_result['error']})"];
}

/** @return array{ok:bool,message:string} */
function admin_students_regenerate_password(PDO $pdo, int $schoolId, int $studentId): array
{
    $user_stmt = $pdo->prepare("
        SELECT u.id, s.full_name
        FROM users u
        JOIN students s ON s.id = u.student_id
        WHERE u.student_id = ? AND u.school_id = ? AND u.role = 'student'
    ");
    $user_stmt->execute([$studentId, $schoolId]);
    $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_row) {
        return ['ok' => false, 'message' => "This student doesn't have a portal login yet."];
    }

    $new_code = admin_generate_temp_code();
    $hash = password_hash($new_code, PASSWORD_BCRYPT);

    $upd = $pdo->prepare('
        UPDATE users
        SET password = ?, is_temp_password = 1, temp_password_plain = ?, reset_requested = 0
        WHERE id = ? AND school_id = ?
    ');
    $upd->execute([$hash, $new_code, $user_row['id'], $schoolId]);

    return ['ok' => true, 'message' => "Password reset for {$user_row['full_name']} — New Access Code: {$new_code}. Share this with the student now; they'll set their own password on first login."];
}

/** @return array{students:array,classes:array,total:int,male:int,female:int} */
function admin_students_fetch_all(PDO $pdo, int $schoolId): array
{
    $student_logins_stmt = $pdo->prepare("SELECT student_id, username, reset_requested FROM users WHERE school_id = ? AND role = 'student' AND student_id IS NOT NULL");
    $student_logins_stmt->execute([$schoolId]);
    $student_logins = [];
    foreach ($student_logins_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $student_logins[(int) $row['student_id']] = $row;
    }

    $students_stmt = $pdo->prepare('
        SELECT s.*, c.class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.school_id = ?
        ORDER BY s.id DESC
    ');
    $students_stmt->execute([$schoolId]);
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

    // URL fields are NOT built here -- the classic page (inside
    // school_admin/) and the JSON endpoint (consumed by the SPA, rooted at
    // scholar/) need different relative paths to the exact same targets.
    // Each caller builds its own; this function only fills in login state.
    foreach ($students as &$row) {
        $login = $student_logins[(int) $row['id']] ?? null;
        $row['login_username'] = $login['username'] ?? null;
        $row['reset_requested'] = $login ? (bool) $login['reset_requested'] : false;
    }
    unset($row);

    $classes_stmt = $pdo->prepare('SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name ASC');
    $classes_stmt->execute([$schoolId]);

    $total_stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE school_id = ?');
    $total_stmt->execute([$schoolId]);

    $male_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND (gender = 'Male' OR gender = 'M' OR sex = 'Male')");
    $male_stmt->execute([$schoolId]);

    $female_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND (gender = 'Female' OR gender = 'F' OR sex = 'Female')");
    $female_stmt->execute([$schoolId]);

    return [
        'students' => $students,
        'classes' => $classes_stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => (int) $total_stmt->fetchColumn(),
        'male' => (int) $male_stmt->fetchColumn(),
        'female' => (int) $female_stmt->fetchColumn(),
    ];
}

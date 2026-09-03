<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — MANAGE PARENT ACCOUNTS: SHARED LOGIC
|--------------------------------------------------------------------------
| Pulled out of school_admin/manage_parents.php so the classic page and the
| JSON endpoint (api/admin/manage_parents.php) mint parent logins the same
| way -- same ownership check on every linked student.
|--------------------------------------------------------------------------
*/

/** @return array{ok:bool,message:string} */
function admin_parents_create(PDO $pdo, int $schoolId, string $fullName, string $username, string $phone, ?string $email, array $studentIds): array
{
    if ($fullName === '' || $username === '' || !$studentIds) {
        return ['ok' => false, 'message' => 'Full name, username, and at least one linked child are required.'];
    }

    // Confirm every selected student actually belongs to this school --
    // never trust IDs from a <select> at face value.
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $check = $pdo->prepare("SELECT id FROM students WHERE id IN ($placeholders) AND school_id = ?");
    $check->execute([...$studentIds, $schoolId]);
    $valid_ids = array_column($check->fetchAll(PDO::FETCH_ASSOC), 'id');

    if (count($valid_ids) !== count($studentIds)) {
        return ['ok' => false, 'message' => 'One or more selected students do not belong to this school.'];
    }

    $temp_password = 'parent' . random_int(1000, 9999);
    $hash = password_hash($temp_password, PASSWORD_BCRYPT);

    try {
        $pdo->beginTransaction();

        $ins_user = $pdo->prepare("
            INSERT INTO users (school_id, username, email, password, role, phone_number, is_temp_password, account_status)
            VALUES (?, ?, ?, ?, 'parent', ?, 1, 'active')
        ");
        $ins_user->execute([$schoolId, $username, $email, $hash, $phone]);
        $new_user_id = (int) $pdo->lastInsertId();

        $ins_link = $pdo->prepare('INSERT INTO parent_students (school_id, user_id, student_id) VALUES (?, ?, ?)');
        foreach ($valid_ids as $sid) {
            $ins_link->execute([$schoolId, $new_user_id, $sid]);
        }

        $pdo->commit();
        return ['ok' => true, 'message' => "Parent account created. Username: {$username} — Temporary password: {$temp_password}"];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not create account: ' . $e->getMessage()];
    }
}

function admin_parents_fetch_students(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name, class_name FROM students WHERE school_id = ? ORDER BY class_name, full_name');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_parents_fetch_list(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.phone_number,
               GROUP_CONCAT(s.full_name SEPARATOR ', ') AS children
        FROM users u
        LEFT JOIN parent_students ps ON ps.user_id = u.id
        LEFT JOIN students s ON s.id = ps.student_id
        WHERE u.school_id = ? AND u.role = 'parent'
        GROUP BY u.id
        ORDER BY u.username
    ");
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

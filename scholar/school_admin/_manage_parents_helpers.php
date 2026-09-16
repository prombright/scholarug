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

/**
 * Replaces a parent's full set of linked children -- simplest, most
 * predictable semantics for a checkbox picker: whatever's checked when
 * Save is clicked is exactly what ends up linked, same convention as
 * editing any other multi-select relationship in this app. This is what
 * makes "a parent can have more than one student" actually usable day to
 * day: admin_parents_create() already supported linking several children
 * at once, but until this, an EXISTING parent (created for one child) had
 * no way to gain a second one later -- e.g. a younger sibling enrolling
 * the following year -- short of a second, disconnected login.
 *
 * @return array{ok:bool,message:string}
 */
function admin_parents_update_links(PDO $pdo, int $schoolId, int $parentUserId, array $studentIds): array
{
    if (!$studentIds) {
        return ['ok' => false, 'message' => 'Select at least one linked child.'];
    }

    $parent_check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND school_id = ? AND role = 'parent'");
    $parent_check->execute([$parentUserId, $schoolId]);
    if (!$parent_check->fetchColumn()) {
        return ['ok' => false, 'message' => 'Parent account not found.'];
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $check = $pdo->prepare("SELECT id FROM students WHERE id IN ($placeholders) AND school_id = ?");
    $check->execute([...$studentIds, $schoolId]);
    $valid_ids = array_column($check->fetchAll(PDO::FETCH_ASSOC), 'id');

    if (count($valid_ids) !== count($studentIds)) {
        return ['ok' => false, 'message' => 'One or more selected students do not belong to this school.'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM parent_students WHERE user_id = ? AND school_id = ?')->execute([$parentUserId, $schoolId]);
        $ins_link = $pdo->prepare('INSERT INTO parent_students (school_id, user_id, student_id) VALUES (?, ?, ?)');
        foreach ($valid_ids as $sid) {
            $ins_link->execute([$schoolId, $parentUserId, $sid]);
        }
        $pdo->commit();
        return ['ok' => true, 'message' => 'Linked children updated.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Could not update: ' . $e->getMessage()];
    }
}

function admin_parents_fetch_students(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name, class_name FROM students WHERE school_id = ? ORDER BY class_name, full_name');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** child_ids alongside the display-friendly children string, so an edit UI can pre-check the right boxes. */
function admin_parents_fetch_list(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.phone_number,
               GROUP_CONCAT(s.full_name SEPARATOR ', ') AS children,
               GROUP_CONCAT(s.id SEPARATOR ',') AS child_ids
        FROM users u
        LEFT JOIN parent_students ps ON ps.user_id = u.id
        LEFT JOIN students s ON s.id = ps.student_id
        WHERE u.school_id = ? AND u.role = 'parent'
        GROUP BY u.id
        ORDER BY u.username
    ");
    $stmt->execute([$schoolId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['child_ids'] = $r['child_ids'] ? array_map('intval', explode(',', $r['child_ids'])) : [];
    }
    return $rows;
}

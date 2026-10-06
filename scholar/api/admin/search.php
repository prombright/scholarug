<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: GLOBAL SEARCH (JSON)
|--------------------------------------------------------------------------
| Backs the search box in AdminLayout.vue's topbar. Matches students (by
| name or student number) and staff (by name or staff code), scoped to
| the logged-in admin's own school -- nothing cross-school.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['school_admin', 'dos', 'headteacher', 'bursar']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$q = trim($_GET['q'] ?? '');

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'students' => [], 'staff' => []], JSON_UNESCAPED_SLASHES);
    exit;
}

$like = '%' . $q . '%';

$students_stmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.student_no, c.class_name, c.stream_name
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE s.school_id = ? AND (s.full_name LIKE ? OR s.student_no LIKE ?)
    ORDER BY s.full_name
    LIMIT 8
");
$students_stmt->execute([$school_id, $like, $like]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$staff_stmt = $pdo->prepare("
    SELECT staff_id AS id, first_name, last_name, staff_code, staff_category, role
    FROM staff
    WHERE school_id = ? AND (CONCAT(first_name, ' ', last_name) LIKE ? OR staff_code LIKE ?)
    ORDER BY first_name
    LIMIT 8
");
$staff_stmt->execute([$school_id, $like, $like]);
$staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'students' => $students,
    'staff' => $staff,
], JSON_UNESCAPED_SLASHES);

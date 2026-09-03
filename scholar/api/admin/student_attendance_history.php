<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: STUDENT ATTENDANCE HISTORY (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/student_attendance_history.php, built on
| school_admin/_student_attendance_history_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_student_attendance_history_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$student_id = (int) ($_GET['id'] ?? 0);

$history = admin_student_attendance_history_fetch($pdo, $school_id, $student_id);

if (!$history['ok']) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $history['message']]);
    exit;
}

echo json_encode([
    'success' => true,
    'student' => $history['student'],
    'rows' => $history['rows'],
], JSON_UNESCAPED_SLASHES);

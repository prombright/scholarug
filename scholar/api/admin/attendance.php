<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: ATTENDANCE (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/attendance.php, built on
| school_admin/_attendance_helpers.php. Same Present/Absent/Late/Excused
| vocabulary as the classic page (distinct from the teacher Roll Call
| tool's own vocabulary -- see that helper file's header comment).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_attendance_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $class_id = (int) ($_GET['class_id'] ?? 0);
    $date = $_GET['date'] ?? date('Y-m-d');

    echo json_encode([
        'success' => true,
        'classes' => admin_attendance_fetch_classes($pdo, $school_id),
        'students' => admin_attendance_fetch_students($pdo, $school_id, $class_id),
        'today_summary' => admin_attendance_today_summary($pdo, $school_id, $date),
        'date' => $date,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $class_id = (int) ($body['class_id'] ?? 0);
    $date = $body['attendance_date'] ?? date('Y-m-d');
    $statuses = is_array($body['attendance'] ?? null) ? $body['attendance'] : [];

    $result = admin_attendance_save($pdo, $school_id, $class_id, $date, $statuses);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'students' => admin_attendance_fetch_students($pdo, $school_id, $class_id),
        'today_summary' => admin_attendance_today_summary($pdo, $school_id, $date),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);

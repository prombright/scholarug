<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — TEACHER: ROLL CALL (JSON)
|--------------------------------------------------------------------------
| JSON twin of teacher_attendance.php, built on the shared
| _attendance_helpers.php. Deliberately unrestricted by class -- see that
| page's header comment: any teacher can take attendance for any class in
| their school, not just ones they're assigned to teach.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_attendance_helpers.php';
require_role(['teacher']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

function attendance_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $classes_stmt = $pdo->prepare('SELECT id, class_name, stream_name FROM classes WHERE school_id = ? ORDER BY class_name');
    $classes_stmt->execute([$school_id]);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

    $sel_class = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
    $sel_date = $_GET['attendance_date'] ?? date('Y-m-d');

    $response = ['success' => true, 'classes' => $classes, 'date' => $sel_date, 'roster' => null, 'taken_at' => null, 'counts' => null];

    if ($sel_class) {
        $roster = attendance_fetch_roster($pdo, $school_id, $sel_class, $sel_date);
        $summary = attendance_summarize($roster);
        $response['roster'] = $roster;
        $response['taken_at'] = $summary['taken_at'];
        $response['counts'] = $summary['counts'];
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $class_id = (int) ($body['class_id'] ?? 0);
    $date = $body['attendance_date'] ?? date('Y-m-d');
    $statuses = is_array($body['status'] ?? null) ? $body['status'] : [];

    if (!$class_id || empty($statuses)) {
        attendance_json_error('Pick a class first.');
    }

    $touched = attendance_save($pdo, $school_id, $class_id, $staff_id, $date, $statuses);

    $roster = attendance_fetch_roster($pdo, $school_id, $class_id, $date);
    $summary = attendance_summarize($roster);

    echo json_encode([
        'success' => true,
        'touched' => $touched,
        'roster' => $roster,
        'taken_at' => $summary['taken_at'],
        'counts' => $summary['counts'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

attendance_json_error('Method not allowed.', 405);

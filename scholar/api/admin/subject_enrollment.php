<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: SUBJECT ENROLLMENT (subject-first) (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/subject_enrollment.php, built on
| school_admin/_subject_enrollment_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_subject_enrollment_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$method = $_SERVER['REQUEST_METHOD'];

function admin_subject_enrollment_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($method === 'GET') {
    $sel_class_id = (int) ($_GET['class_id'] ?? 0);
    $sel_subject_id = (int) ($_GET['subject_id'] ?? 0);
    $state = admin_subject_enrollment_load($pdo, $school_id, $sel_class_id, $sel_subject_id);
    echo json_encode(['success' => true] + $state, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $sel_class_id = (int) ($body['class_id'] ?? 0);
    $sel_subject_id = (int) ($body['subject_id'] ?? 0);

    $state = admin_subject_enrollment_load($pdo, $school_id, $sel_class_id, $sel_subject_id);
    if (!$state['sel_class'] || !$state['sel_subject']) {
        admin_subject_enrollment_json_error('Choose a valid class and elective subject first.');
    }

    $result = admin_subject_enrollment_save($pdo, $school_id, $state['sel_class'], $state['sel_subject'], $body['student_ids'] ?? []);
    if (!$result['ok']) {
        admin_subject_enrollment_json_error($result['message']);
    }

    $state = admin_subject_enrollment_load($pdo, $school_id, $sel_class_id, $sel_subject_id);
    echo json_encode(['success' => true, 'message' => $result['message']] + $state, JSON_UNESCAPED_SLASHES);
    exit;
}

admin_subject_enrollment_json_error('Method not allowed.', 405);

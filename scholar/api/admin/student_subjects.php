<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: STUDENT SUBJECTS / ELECTIVES (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/student_subjects.php, built on
| school_admin/_student_subjects_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_student_subjects_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_student_subjects_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function admin_student_subjects_snapshot(array $profile): array
{
    return [
        'success' => true,
        'student' => $profile['student'],
        'is_a_level' => $profile['is_a_level'],
        'class_name' => $profile['class_name'],
        'electives' => $profile['electives'],
        'enrolled_ids' => $profile['enrolled_ids'],
        'combinations' => $profile['combinations'],
        'combo_subject_map' => $profile['combo_subject_map'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$student_id = (int) ($_GET['student_id'] ?? 0);

if ($method === 'GET') {
    $profile = admin_student_subjects_load($pdo, $school_id, $student_id);
    if (!$profile['ok']) {
        admin_student_subjects_json_error($profile['message'], 404);
    }
    echo json_encode(admin_student_subjects_snapshot($profile), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $student_id = (int) ($body['student_id'] ?? 0);

    $result = admin_student_subjects_save(
        $pdo, $school_id, $student_id,
        $body['subject_ids'] ?? [],
        (int) ($body['combination_id'] ?? 0)
    );

    if (!$result['ok']) {
        admin_student_subjects_json_error($result['message']);
    }

    $profile = admin_student_subjects_load($pdo, $school_id, $student_id);
    $snapshot = admin_student_subjects_snapshot($profile);
    $snapshot['message'] = $result['message'];
    echo json_encode($snapshot, JSON_UNESCAPED_SLASHES);
    exit;
}

admin_student_subjects_json_error('Method not allowed.', 405);

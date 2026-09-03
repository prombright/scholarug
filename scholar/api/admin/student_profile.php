<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: STUDENT PROFILE (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/student_profile.php, built on
| school_admin/_student_profile_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_student_profile_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$student_id = (int) ($_GET['id'] ?? 0);

$profile = admin_student_profile_fetch($pdo, $school_id, $student_id);

if (!$profile['ok']) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $profile['message']]);
    exit;
}

echo json_encode([
    'success' => true,
    'student' => $profile['student'],
    'attendance' => $profile['attendance'],
    'fees' => $profile['fees'],
    'results' => $profile['results'],
], JSON_UNESCAPED_SLASHES);

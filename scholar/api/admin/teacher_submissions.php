<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: TEACHER SUBMISSIONS (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/teacher_submissions.php. Read-only.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_teacher_submissions_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$filters = admin_teacher_submissions_fetch_filters($pdo, $school_id);
$assessment_id = isset($_GET['assessment_id']) && $_GET['assessment_id'] !== '' ? (int) $_GET['assessment_id'] : null;
$class_id = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;

$rows = [];
if ($assessment_id !== null) {
    $allowed = array_map('intval', array_column($filters['assessments'], 'id'));
    if (in_array($assessment_id, $allowed, true)) {
        $rows = admin_teacher_submissions_fetch_rows($pdo, $school_id, $assessment_id, $class_id);
    } else {
        $assessment_id = null;
    }
}

echo json_encode([
    'success' => true,
    'assessments' => $filters['assessments'],
    'classes' => $filters['classes'],
    'selected_assessment' => $assessment_id,
    'rows' => $rows,
], JSON_UNESCAPED_SLASHES);

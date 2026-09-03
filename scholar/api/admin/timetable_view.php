<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: TIMETABLE VIEW / MANUAL EDIT (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/timetable_view.php, built on
| school_admin/_timetable_view_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_timetable_view_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$method = $_SERVER['REQUEST_METHOD'];

$overview = admin_timetable_view_overview($pdo, $school_id);
$term = $overview['term'];
$year = $overview['year'];
$classes = $overview['classes'];

$message = null;
$selectedClassId = (int) ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $cellClassId = (int) ($body['class_id'] ?? 0);
    $day = (int) ($body['day'] ?? 0);
    $periodId = (int) ($body['period_id'] ?? 0);
    $assignmentId = (int) ($body['assignment_id'] ?? 0);

    $save_result = admin_timetable_view_save_cell($pdo, $school_id, $term, $year, $cellClassId, $day, $periodId, $assignmentId);
    $message = $save_result;
    $selectedClassId = $cellClassId;
}

$grid = admin_timetable_view_grid($pdo, $school_id, $term, $year, $selectedClassId);

echo json_encode([
    'success' => true,
    'term' => $term,
    'year' => $year,
    'classes' => $classes,
    'selected_class_id' => $selectedClassId,
    'day_names' => SCHOLAR_TIMETABLE_DAY_NAMES,
    'grid_rows' => $grid['grid_rows'],
    'active_days' => $grid['active_days'],
    'class_assignments' => $grid['class_assignments'],
    'entries' => $grid['entries'],
    'save_result' => $message,
], JSON_UNESCAPED_SLASHES);

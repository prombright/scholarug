<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: TIMETABLE GENERATE (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/timetable_generate.php, built on
| school_admin/_timetable_generate_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_timetable_engine.php';
require_once __DIR__ . '/../../school_admin/_timetable_generate_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($body['generate'])) {
        $overview = admin_timetable_generate_overview($pdo, $school_id);
        $result = admin_timetable_generate_run($pdo, $school_id, $overview['term'], $overview['year']);
    }
}

$overview = admin_timetable_generate_overview($pdo, $school_id);

echo json_encode([
    'success' => true,
    'overview' => $overview,
    'result' => $result,
], JSON_UNESCAPED_SLASHES);

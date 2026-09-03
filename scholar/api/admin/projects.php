<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: PROJECTS (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/projects.php, built on
| school_admin/_projects_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_projects_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$admin_user_id = (int) ($_SESSION['user_id'] ?? 0);

function admin_projects_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$project_classes = admin_projects_eligible_classes($pdo, $school_id);
$teachers = admin_projects_teachers($pdo, $school_id);

$method = $_SERVER['REQUEST_METHOD'];
$sel_class_id = (int) ($_GET['class_id'] ?? 0);
$message = null;

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $sel_class_id = (int) ($body['class_id'] ?? 0);
    $allowed_ids = array_map('intval', array_column($project_classes, 'id'));
    if (!in_array($sel_class_id, $allowed_ids, true)) {
        admin_projects_json_error('Projects are only tracked for S.3-S.6.');
    }

    $action = $body['action'] ?? '';
    if ($action === 'create_project') {
        $result = admin_projects_create(
            $pdo, $school_id, $sel_class_id, $admin_user_id,
            trim((string) ($body['title'] ?? '')), trim((string) ($body['description'] ?? '')), (int) ($body['teacher_id'] ?? 0)
        );
        if (!$result['ok']) {
            admin_projects_json_error($result['message']);
        }
        $message = $result['message'];
    } elseif ($action === 'assign_teacher') {
        $result = admin_projects_assign_teacher(
            $pdo, $school_id, $sel_class_id,
            (int) ($body['project_id'] ?? 0), (int) ($body['teacher_id'] ?? 0)
        );
        if (!$result['ok']) {
            admin_projects_json_error($result['message']);
        }
        $message = $result['message'];
    } else {
        admin_projects_json_error('Unknown action.');
    }
}

$state = $sel_class_id > 0 ? admin_projects_class_state($pdo, $school_id, $sel_class_id) : null;

echo json_encode([
    'success' => true,
    'classes' => $project_classes,
    'teachers' => $teachers,
    'project' => $state['project'] ?? null,
    'assigned_teachers' => $state['assigned_teachers'] ?? [],
    'students' => $state['students'] ?? [],
    'stages_by_student' => $state['stages_by_student'] ?? [],
    'message' => $message,
], JSON_UNESCAPED_SLASHES);

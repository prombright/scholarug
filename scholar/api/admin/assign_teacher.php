<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: TEACHER ASSIGNMENTS (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/assign_teacher.php, built on
| school_admin/_assign_teacher_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_assign_teacher_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_assign_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function admin_assign_base_data(PDO $pdo, int $schoolId): array
{
    $teachers = admin_assign_fetch_teachers($pdo, $schoolId);
    return [
        'teachers' => $teachers,
        'departments' => admin_assign_fetch_departments($pdo, $schoolId),
        'subjects_by_name' => admin_assign_subjects_by_name(admin_assign_fetch_subjects($pdo, $schoolId)),
        'classes' => admin_assign_fetch_classes($pdo, $schoolId),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $staff_id = (int) ($_GET['staff_id'] ?? 0);
    $base = admin_assign_base_data($pdo, $school_id);
    $detail = $staff_id > 0 ? admin_assign_fetch_teacher_detail($pdo, $school_id, $base['teachers'], $staff_id) : null;

    echo json_encode(['success' => true] + $base + ['detail' => $detail], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $staff_id = (int) ($body['staff_id'] ?? 0);
    if ($staff_id <= 0) {
        admin_assign_json_error('No teacher selected.');
    }

    $message = null;
    switch ($action) {
        case 'save_departments':
            admin_assign_save_departments($pdo, $school_id, $staff_id, array_map('intval', $body['department_ids'] ?? []));
            $message = 'Departments updated.';
            break;
        case 'add_assignment':
            $result = admin_assign_add_assignment($pdo, $school_id, $staff_id, (int) ($body['subject_id'] ?? 0), (int) ($body['class_id'] ?? 0), (int) ($body['periods_per_week'] ?? 5), (int) ($body['paper_number'] ?? 1));
            if (!$result['ok']) admin_assign_json_error($result['message']);
            $message = $result['message'];
            break;
        case 'update_periods':
            admin_assign_update_periods($pdo, $school_id, $staff_id, (int) ($body['assignment_id'] ?? 0), (int) ($body['periods_per_week'] ?? 5));
            $message = 'Periods/week updated.';
            break;
        case 'remove_assignment':
            admin_assign_remove_assignment($pdo, $school_id, $staff_id, (int) ($body['assignment_id'] ?? 0));
            $message = 'Assignment removed.';
            break;
        case 'change_role':
            $result = admin_assign_change_role($pdo, $school_id, $staff_id, $body['role'] ?? '');
            if (!$result['ok']) admin_assign_json_error($result['message']);
            $message = $result['message'];
            break;
        default:
            admin_assign_json_error('Unknown action.');
    }

    $base = admin_assign_base_data($pdo, $school_id);
    $detail = admin_assign_fetch_teacher_detail($pdo, $school_id, $base['teachers'], $staff_id);
    echo json_encode(['success' => true, 'message' => $message] + $base + ['detail' => $detail], JSON_UNESCAPED_SLASHES);
    exit;
}

admin_assign_json_error('Method not allowed.', 405);

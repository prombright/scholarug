<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: CLASSES (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/classes.php, built on
| school_admin/_classes_helpers.php -- identical validation/ownership
| rules for every action.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_subject_helpers.php';
require_once __DIR__ . '/../../school_admin/_classes_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$school_type_stmt = $pdo->prepare('SELECT school_type FROM schools WHERE id = ?');
$school_type_stmt->execute([$school_id]);
$school_type = $school_type_stmt->fetchColumn() ?: 'Secondary';

$LEVEL_CLASSES = scholar_class_ladder($school_type);
$ALL_CLASS_NAMES = array_merge(...array_values($LEVEL_CLASSES));

admin_classes_ensure_base_classes($pdo, $school_id, $ALL_CLASS_NAMES);

function admin_classes_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function admin_classes_snapshot(PDO $pdo, int $schoolId, array $allClassNames, array $levelClasses, string $schoolType): array
{
    $data = admin_classes_fetch_all($pdo, $schoolId, $allClassNames);
    return [
        'success' => true,
        'school_type' => $schoolType,
        'all_class_names' => $allClassNames,
        'level_classes' => $levelClasses,
        'classes' => $data['classes'],
        'teaching_staff' => $data['teaching_staff'],
        'streams_by_class' => $data['streams_by_class'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(admin_classes_snapshot($pdo, $school_id, $ALL_CLASS_NAMES, $LEVEL_CLASSES, $school_type), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $result = null;

    switch ($action) {
        case 'add_stream':
            $result = admin_classes_add_stream($pdo, $school_id, $ALL_CLASS_NAMES, trim((string) ($body['class_name'] ?? '')), trim((string) ($body['stream_name'] ?? '')));
            break;
        case 'seed_alevel_streams':
            $result = admin_classes_seed_alevel($pdo, $school_id, $school_type, $LEVEL_CLASSES, trim((string) ($body['class_name'] ?? '')));
            break;
        case 'delete_class':
            admin_classes_delete($pdo, $school_id, (int) ($body['class_id'] ?? 0));
            $result = ['ok' => true, 'message' => 'Class deleted. Students in it are now unassigned rather than deleted.'];
            break;
        case 'assign_class_teacher':
            $result = admin_classes_assign_teacher($pdo, $school_id, (int) ($body['class_id'] ?? 0), (int) ($body['teacher_staff_id'] ?? 0));
            break;
        case 'remove_class_teacher':
            admin_classes_remove_teacher($pdo, $school_id, (int) ($body['class_id'] ?? 0));
            $result = ['ok' => true, 'message' => 'Class teacher removed.'];
            break;
        default:
            admin_classes_json_error('Unknown action.');
    }

    if (!$result['ok']) {
        admin_classes_json_error($result['message']);
    }

    $snapshot = admin_classes_snapshot($pdo, $school_id, $ALL_CLASS_NAMES, $LEVEL_CLASSES, $school_type);
    $snapshot['message'] = $result['message'];
    echo json_encode($snapshot, JSON_UNESCAPED_SLASHES);
    exit;
}

admin_classes_json_error('Method not allowed.', 405);

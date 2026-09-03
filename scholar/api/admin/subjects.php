<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: SUBJECT CATALOG (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/subject_catalog.php, built on
| school_admin/_subject_catalog_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_subject_helpers.php';
require_once __DIR__ . '/../../school_admin/_subject_catalog_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$school_type_stmt = $pdo->prepare('SELECT school_type FROM schools WHERE id = ?');
$school_type_stmt->execute([$school_id]);
$school_type = $school_type_stmt->fetchColumn() ?: 'Secondary';

function admin_subjects_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $level = in_array($_GET['level_type'] ?? '', ['O-Level', 'A-Level'], true) ? $_GET['level_type'] : 'O-Level';
    $combosData = admin_subjects_fetch_combinations_data($pdo, $school_id);

    echo json_encode([
        'success' => true,
        'school_type' => $school_type,
        'catalog' => admin_subjects_fetch_catalog($pdo, $school_id, $level),
        'combinations' => $combosData['combinations'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    if ($school_type !== 'Secondary') {
        admin_subjects_json_error('This catalog covers Secondary schools only.');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'adopt_subjects') {
        $result = admin_subjects_adopt($pdo, $school_id, $body['level_type'] ?? '', array_map('intval', $body['catalog_ids'] ?? []));
    } elseif ($action === 'adopt_combinations') {
        $result = admin_subjects_adopt_combinations($pdo, $school_id, array_map('intval', $body['combo_ids'] ?? []));
    } else {
        admin_subjects_json_error('Unknown action.');
    }

    $level = in_array($body['level_type'] ?? '', ['O-Level', 'A-Level'], true) ? $body['level_type'] : 'O-Level';
    $combosData = admin_subjects_fetch_combinations_data($pdo, $school_id);

    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'message_type' => $result['type'],
        'catalog' => admin_subjects_fetch_catalog($pdo, $school_id, $level),
        'combinations' => $combosData['combinations'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

admin_subjects_json_error('Method not allowed.', 405);

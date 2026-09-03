<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: ASSESSMENTS (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/assessments.php, built on
| school_admin/_assessments_helpers.php -- identical 100% report-weight cap.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_assessments_helpers.php';
require_role(['school_admin', 'dos']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_assessments_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(['success' => true] + admin_assessments_fetch_list($pdo, $school_id), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'create_assessment') {
        $result = admin_assessments_create(
            $pdo, $school_id,
            trim((string) ($body['title'] ?? '')),
            (float) ($body['weight_percentage'] ?? 0),
            (string) ($body['term'] ?? ''),
            (int) ($body['year'] ?? date('Y')),
            (string) ($body['status'] ?? 'Draft'),
            (bool) ($body['include_in_report'] ?? false)
        );
    } elseif ($action === 'update_assessment') {
        $result = admin_assessments_update(
            $pdo, $school_id,
            (int) ($body['id'] ?? 0),
            (float) ($body['weight_percentage'] ?? 0),
            (string) ($body['status'] ?? 'Draft'),
            (bool) ($body['include_in_report'] ?? false)
        );
    } else {
        admin_assessments_json_error('Unknown action.');
    }

    if (!$result['ok']) {
        admin_assessments_json_error($result['message']);
    }

    $snapshot = admin_assessments_fetch_list($pdo, $school_id);
    echo json_encode(['success' => true, 'message' => $result['message']] + $snapshot, JSON_UNESCAPED_SLASHES);
    exit;
}

admin_assessments_json_error('Method not allowed.', 405);

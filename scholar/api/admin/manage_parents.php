<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: MANAGE PARENT ACCOUNTS (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/manage_parents.php, built on
| school_admin/_manage_parents_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_manage_parents_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_parents_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'students' => admin_parents_fetch_students($pdo, $school_id),
        'parents' => admin_parents_fetch_list($pdo, $school_id),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $result = admin_parents_create(
        $pdo, $school_id,
        trim((string) ($body['full_name'] ?? '')),
        trim((string) ($body['username'] ?? '')),
        trim((string) ($body['phone'] ?? '')),
        trim((string) ($body['email'] ?? '')) ?: null,
        array_map('intval', $body['student_ids'] ?? [])
    );
    if (!$result['ok']) {
        admin_parents_json_error($result['message']);
    }
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'students' => admin_parents_fetch_students($pdo, $school_id),
        'parents' => admin_parents_fetch_list($pdo, $school_id),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

admin_parents_json_error('Method not allowed.', 405);

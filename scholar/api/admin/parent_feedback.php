<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: PARENT FEEDBACK INBOX (JSON)
|--------------------------------------------------------------------------
| Vue-only, no classic PHP twin -- same convention as Elections/Projects
| (school_admin's landing experience is the Vue app now; new features
| don't get a second classic-HTML copy). Built on
| school_admin/_parent_feedback_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../school_admin/_parent_feedback_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_feedback_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'messages' => admin_feedback_fetch_list($pdo, $school_id),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $feedback_id = (int) ($body['id'] ?? 0);

    switch ($action) {
        case 'mark_read':
            admin_feedback_mark_read($pdo, $school_id, $feedback_id);
            $result = ['ok' => true, 'message' => 'Marked as read.'];
            break;
        case 'respond':
            $result = admin_feedback_respond($pdo, $school_id, $feedback_id, trim((string) ($body['response'] ?? '')));
            break;
        default:
            admin_feedback_json_error('Unknown action.');
    }

    if (!$result['ok']) {
        admin_feedback_json_error($result['message']);
    }
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'messages' => admin_feedback_fetch_list($pdo, $school_id),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

admin_feedback_json_error('Method not allowed.', 405);

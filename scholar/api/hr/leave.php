<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — HR: LEAVE REVIEW (JSON)
|--------------------------------------------------------------------------
| JSON twin of hr/leave_review.php, built on hr/_leave_review_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../hr/_leave_review_helpers.php';
require_role(['school_admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$reviewer_id = (int) ($_SESSION['user_id'] ?? 0);
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $result = hr_leave_review($pdo, $school_id, $reviewer_id, (int) ($body['request_id'] ?? 0), (string) ($body['action'] ?? ''));
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }
    $message = $result['message'];
}

echo json_encode([
    'success' => true,
    'requests' => hr_leave_requests_list($pdo, $school_id),
    'message' => $message,
], JSON_UNESCAPED_SLASHES);

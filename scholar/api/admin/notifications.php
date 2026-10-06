<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: NOTIFICATIONS (JSON)
|--------------------------------------------------------------------------
| Backs the notification bell in AdminLayout.vue's topbar. GET lazily
| (re)generates any newly-due notifications (see _notifications_helpers.php)
| then returns the recent feed + unread count. POST marks one or all as
| read.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_notifications_helpers.php';
require_role(['school_admin', 'dos', 'headteacher', 'bursar']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_json();
}

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_notifications_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    scholar_generate_notifications($pdo, $school_id, current_term(), current_year());

    $stmt = $pdo->prepare("SELECT id, title, message, is_read, created_at FROM notifications WHERE school_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$school_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE school_id = ? AND is_read = 0");
    $unread_stmt->execute([$school_id]);
    $unread_count = (int) $unread_stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'items' => $items,
        'unread_count' => $unread_count,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int) ($body['id'] ?? 0);
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND school_id = ?")->execute([$id, $school_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE school_id = ? AND is_read = 0")->execute([$school_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    admin_notifications_json_error('Unknown action.');
}

admin_notifications_json_error('Method not allowed.', 405);

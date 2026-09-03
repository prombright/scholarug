<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — CONTACT DEVELOPER (JSON)
|--------------------------------------------------------------------------
| JSON twin of school_admin/contact_developer.php -- one thread per school
| (developer_conversations.UNIQUE(school_id)), auto-created on first
| message. Small/self-contained enough that no separate helper file is
| needed; this endpoint IS the single source of truth alongside the
| classic page for this one thread's read/write logic.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

$find = $pdo->prepare('SELECT id FROM developer_conversations WHERE school_id = ?');
$find->execute([$school_id]);
$conversation_id = $find->fetchColumn();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (($_GET['poll'] ?? '') === '1') {
        if (!$conversation_id) {
            echo json_encode(['success' => true, 'messages' => []]);
            exit;
        }
        $after_id = (int) ($_GET['after_id'] ?? 0);
        $pdo->prepare("UPDATE developer_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'developer' AND read_at IS NULL")
            ->execute([$conversation_id]);
        $poll_stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM developer_messages WHERE conversation_id = ? AND id > ? ORDER BY created_at ASC');
        $poll_stmt->execute([$conversation_id, $after_id]);
        echo json_encode(['success' => true, 'messages' => $poll_stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $messages = [];
    if ($conversation_id) {
        $pdo->prepare("UPDATE developer_messages SET read_at = NOW() WHERE conversation_id = ? AND sender_role = 'developer' AND read_at IS NULL")
            ->execute([$conversation_id]);
        $msgs_stmt = $pdo->prepare('SELECT id, sender_role, body, created_at FROM developer_messages WHERE conversation_id = ? ORDER BY created_at ASC');
        $msgs_stmt->execute([$conversation_id]);
        $messages = $msgs_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['success' => true, 'messages' => $messages], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body_json = json_decode(file_get_contents('php://input'), true) ?: [];
    $body = trim((string) ($body_json['body'] ?? ''));

    if ($body === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message cannot be empty.']);
        exit;
    }

    if (!$conversation_id) {
        $ins = $pdo->prepare('INSERT INTO developer_conversations (school_id) VALUES (?)');
        $ins->execute([$school_id]);
        $conversation_id = (int) $pdo->lastInsertId();
    }

    $send = $pdo->prepare("INSERT INTO developer_messages (conversation_id, sender_role, body) VALUES (?, 'school_admin', ?)");
    $send->execute([$conversation_id, $body]);

    echo json_encode([
        'success' => true,
        'message' => [
            'id' => (int) $pdo->lastInsertId(),
            'sender_role' => 'school_admin',
            'body' => $body,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);

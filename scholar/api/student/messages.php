<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — STUDENT: MESSAGES (JSON)
|--------------------------------------------------------------------------
| JSON twin of student_messages.php, built on _student_messages_helpers.php
| -- same reachable-teacher scoping, same conversation lookups, same
| polling shapes the classic page's own inline JS already used.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_student_messages_helpers.php';
require_role(['student']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$student_id = current_student_id();

$stu_stmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
$stu_stmt->execute([$student_id, $school_id]);
$class_id = (int) ($stu_stmt->fetchColumn() ?: 0);

function student_messages_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $teachers = student_reachable_teachers($pdo, $school_id, $class_id);
    $allowed_ids = array_map('intval', array_column($teachers, 'staff_id'));
    $threads = student_message_threads($pdo, $school_id, $student_id);

    if (($_GET['poll_threads'] ?? '') === '1') {
        echo json_encode(['success' => true, 'threads' => student_message_rows($teachers, $threads)]);
        exit;
    }

    $teacher_id = isset($_GET['teacher_id']) ? (int) $_GET['teacher_id'] : null;

    if ($teacher_id !== null && !in_array($teacher_id, $allowed_ids, true)) {
        student_messages_json_error('You can only message a teacher who teaches your class.', 403);
    }

    if ($teacher_id !== null && ($_GET['poll'] ?? '') === '1') {
        $conversation_id = student_find_or_create_conversation($pdo, $school_id, $student_id, $teacher_id);
        $after_id = (int) ($_GET['after_id'] ?? 0);
        echo json_encode(['success' => true, 'messages' => student_poll_messages($pdo, $conversation_id, $after_id)]);
        exit;
    }

    $response = ['success' => true, 'teachers' => student_message_rows($teachers, $threads), 'open' => null];

    if ($teacher_id !== null) {
        $teacher_name = '';
        foreach ($teachers as $t) {
            if ((int) $t['staff_id'] === $teacher_id) $teacher_name = $t['teacher_name'];
        }
        $conversation_id = student_find_or_create_conversation($pdo, $school_id, $student_id, $teacher_id);
        $response['open'] = [
            'teacher_id' => $teacher_id,
            'teacher_name' => $teacher_name,
            'messages' => student_conversation_messages($pdo, $conversation_id),
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body_json = json_decode(file_get_contents('php://input'), true) ?: [];
    $teacher_id = (int) ($body_json['teacher_id'] ?? 0);
    $body = trim((string) ($body_json['body'] ?? ''));

    $teachers = student_reachable_teachers($pdo, $school_id, $class_id);
    $allowed_ids = array_map('intval', array_column($teachers, 'staff_id'));
    if (!in_array($teacher_id, $allowed_ids, true)) {
        student_messages_json_error('You can only message a teacher who teaches your class.', 403);
    }
    if ($body === '') {
        student_messages_json_error('Message cannot be empty.');
    }

    $conversation_id = student_find_or_create_conversation($pdo, $school_id, $student_id, $teacher_id);
    $pdo->prepare("INSERT INTO conversation_messages (conversation_id, sender_role, body) VALUES (?, 'student', ?)")->execute([$conversation_id, $body]);

    echo json_encode([
        'success' => true,
        'message' => [
            'id' => (int) $pdo->lastInsertId(),
            'sender_role' => 'student',
            'body' => $body,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

student_messages_json_error('Method not allowed.', 405);

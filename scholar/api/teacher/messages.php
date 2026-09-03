<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — TEACHER: MESSAGES (JSON)
|--------------------------------------------------------------------------
| JSON twin of teacher_messages.php, built on _teacher_messages_helpers.php
| -- same roster-scoping rules, same conversation lookups, same polling
| shapes (poll_threads / poll+after_id) the classic page's own inline JS
| already used, just served from one real endpoint instead of the page
| multiplexing on its own URL.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_teacher_messages_helpers.php';
require_role(['teacher']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

function messages_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/** @return array|null the matching thread row, or null if this teacher has no conversation with that student */
function messages_find_thread(array $threads, int $studentId): ?array
{
    foreach ($threads as $th) {
        if ((int) $th['student_id'] === $studentId) {
            return $th;
        }
    }
    return null;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Sidebar live refresh -- same shape the classic page's own poll used.
    if (($_GET['poll_threads'] ?? '') === '1') {
        $threads = teacher_message_threads($pdo, $school_id, $staff_id);
        echo json_encode(['success' => true, 'threads' => array_map(static fn($th) => [
            'student_id' => (int) $th['student_id'],
            'student_name' => $th['student_name'],
            'unread' => (int) $th['unread'],
            'last_at' => $th['last_at'],
        ], $threads)]);
        exit;
    }

    $student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;

    // Open thread's own poll for new messages.
    if ($student_id !== null && ($_GET['poll'] ?? '') === '1') {
        $threads = teacher_message_threads($pdo, $school_id, $staff_id);
        $thread = messages_find_thread($threads, $student_id);
        if (!$thread) {
            messages_json_error("You don't have a conversation with that student.", 404);
        }
        $after_id = (int) ($_GET['after_id'] ?? 0);
        echo json_encode(['success' => true, 'messages' => teacher_poll_messages($pdo, (int) $thread['id'], $after_id)]);
        exit;
    }

    $my_classes = teacher_reachable_classes($pdo, $school_id, $staff_id);
    $threads = teacher_message_threads($pdo, $school_id, $staff_id);

    $response = ['success' => true, 'classes' => $my_classes, 'threads' => $threads, 'roster' => null, 'open' => null];

    $class_id = isset($_GET['class_id']) ? (int) $_GET['class_id'] : null;
    if ($class_id !== null) {
        $selected_class = null;
        foreach ($my_classes as $c) {
            if ((int) $c['id'] === $class_id) $selected_class = $c;
        }
        if ($selected_class) {
            $response['roster'] = teacher_class_roster($pdo, $school_id, $staff_id, $selected_class);
        }
    }

    if ($student_id !== null) {
        $thread = messages_find_thread($threads, $student_id);
        if (!$thread) {
            messages_json_error("You don't have a conversation with that student.", 404);
        }
        $response['open'] = [
            'student_id' => $student_id,
            'student_name' => $thread['student_name'],
            'messages' => teacher_conversation_messages($pdo, (int) $thread['id']),
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body_json = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body_json['action'] ?? '';
    $body = trim((string) ($body_json['body'] ?? ''));

    if ($action === 'send_individual') {
        $class_id = (int) ($body_json['class_id'] ?? 0);
        $target_id = (int) ($body_json['student_id'] ?? 0);

        $my_classes = teacher_reachable_classes($pdo, $school_id, $staff_id);
        $selected_class = null;
        foreach ($my_classes as $c) {
            if ((int) $c['id'] === $class_id) $selected_class = $c;
        }
        if (!$selected_class) {
            messages_json_error('Pick a class first.');
        }
        $roster_ids = array_map('intval', array_column(teacher_class_roster($pdo, $school_id, $staff_id, $selected_class), 'id'));

        $result = teacher_send_individual($pdo, $school_id, $staff_id, $roster_ids, $target_id, $body);
        if (!$result['ok']) {
            messages_json_error($result['error']);
        }
        echo json_encode(['success' => true, 'student_id' => $target_id]);
        exit;
    }

    if ($action === 'send_broadcast') {
        $class_id = (int) ($body_json['class_id'] ?? 0);

        $my_classes = teacher_reachable_classes($pdo, $school_id, $staff_id);
        $selected_class = null;
        foreach ($my_classes as $c) {
            if ((int) $c['id'] === $class_id) $selected_class = $c;
        }
        if (!$selected_class) {
            messages_json_error('Pick a class first.');
        }
        $roster_ids = array_map('intval', array_column(teacher_class_roster($pdo, $school_id, $staff_id, $selected_class), 'id'));

        $result = teacher_send_broadcast($pdo, $school_id, $staff_id, $roster_ids, $body);
        if (!$result['ok']) {
            messages_json_error($result['error']);
        }
        echo json_encode(['success' => true, 'count' => $result['count']]);
        exit;
    }

    if ($action === 'send_message') {
        $student_id = (int) ($body_json['student_id'] ?? 0);
        $threads = teacher_message_threads($pdo, $school_id, $staff_id);
        $thread = messages_find_thread($threads, $student_id);
        if (!$thread) {
            messages_json_error("You don't have a conversation with that student.", 404);
        }
        if ($body === '') {
            messages_json_error('Message cannot be empty.');
        }
        $pdo->prepare("INSERT INTO conversation_messages (conversation_id, sender_role, body) VALUES (?, 'teacher', ?)")->execute([$thread['id'], $body]);
        echo json_encode([
            'success' => true,
            'message' => [
                'id' => (int) $pdo->lastInsertId(),
                'sender_role' => 'teacher',
                'body' => $body,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
        exit;
    }

    messages_json_error('Unknown action.');
}

messages_json_error('Method not allowed.', 405);

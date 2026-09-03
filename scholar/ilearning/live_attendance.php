<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — iLEARNING LIVE SESSION: ATTENDANCE (event-driven, not polled)
|--------------------------------------------------------------------------
| Called from live_session_room.php's Jitsi videoConferenceJoined/Left/
| readyToClose callbacks. A student's join/leave logs ilearning_live_
| attendance; a teacher's leave marks the whole session 'ended'.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher', 'student']);

header('Content-Type: application/json');

$school_id = current_school_id();
$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$session_id = (int) ($input['session_id'] ?? 0);
$event = in_array($input['event'] ?? '', ['joined', 'left'], true) ? $input['event'] : null;

if (!$event) {
    http_response_code(400);
    echo json_encode(['status' => 'error']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM ilearning_live_sessions WHERE id = ? AND school_id = ?');
$stmt->execute([$session_id, $school_id]);
$session = $stmt->fetch();
if (!$session) {
    http_response_code(404);
    echo json_encode(['status' => 'error']);
    exit;
}

if ($_SESSION['role'] === 'teacher') {
    if ((int) $session['teacher_id'] === (int) ($_SESSION['staff_id'] ?? 0) && $event === 'left') {
        $pdo->prepare("UPDATE ilearning_live_sessions SET status = 'ended' WHERE id = ?")->execute([$session_id]);
    }
} else {
    $student_id = current_student_id();
    $stuStmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
    $stuStmt->execute([$student_id, $school_id]);
    if ((int) $stuStmt->fetchColumn() !== (int) $session['class_id']) {
        http_response_code(403);
        echo json_encode(['status' => 'error']);
        exit;
    }

    if ($event === 'joined') {
        $pdo->prepare('INSERT INTO ilearning_live_attendance (session_id, student_id, joined_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE joined_at = LEAST(joined_at, VALUES(joined_at))')
            ->execute([$session_id, $student_id]);
    } else {
        $pdo->prepare('UPDATE ilearning_live_attendance SET left_at = NOW() WHERE session_id = ? AND student_id = ?')
            ->execute([$session_id, $student_id]);
    }
}

echo json_encode(['status' => 'ok']);

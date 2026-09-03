<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — iLEARNING LIVE SESSION: Q&A LIST (polled every ~5s)
|--------------------------------------------------------------------------
| Same poll-based pattern proven in payments/subscription_status.php --
| this stack has no WebSocket/push capability, so ~5s latency is the
| deliberate, acceptable tradeoff for classroom Q&A (not sub-second chat).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher', 'student']);

header('Content-Type: application/json');

$school_id = current_school_id();
$session_id = (int) ($_GET['session_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM ilearning_live_sessions WHERE id = ? AND school_id = ?');
$stmt->execute([$session_id, $school_id]);
$session = $stmt->fetch();
if (!$session) {
    http_response_code(404);
    echo json_encode(['questions' => []]);
    exit;
}

$isTeacher = $_SESSION['role'] === 'teacher';

if ($isTeacher) {
    if ((int) $session['teacher_id'] !== (int) ($_SESSION['staff_id'] ?? 0)) {
        http_response_code(403);
        echo json_encode(['questions' => []]);
        exit;
    }
    // Teacher sees every question, public and private.
    $q_stmt = $pdo->prepare('SELECT id, question_text, visibility, answer_text FROM ilearning_live_questions WHERE session_id = ? ORDER BY asked_at ASC');
    $q_stmt->execute([$session_id]);
} else {
    $student_id = current_student_id();
    $stuStmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
    $stuStmt->execute([$student_id, $school_id]);
    if ((int) $stuStmt->fetchColumn() !== (int) $session['class_id']) {
        http_response_code(403);
        echo json_encode(['questions' => []]);
        exit;
    }
    // Student sees every public question plus their own private ones.
    $q_stmt = $pdo->prepare(
        "SELECT id, question_text, visibility, answer_text FROM ilearning_live_questions
         WHERE session_id = ? AND (visibility = 'public' OR student_id = ?) ORDER BY asked_at ASC"
    );
    $q_stmt->execute([$session_id, $student_id]);
}

echo json_encode(['questions' => $q_stmt->fetchAll()]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_json();
}

header('Content-Type: application/json');

$school_id = current_school_id();

// See live_attendance.php's comment on the same check -- a session's own
// AJAX endpoints previously never re-verified the Live Classes add-on is
// still active, only the page shell did.
if (!scholar_ilearning_addon_is_active($pdo, $school_id)) {
    http_response_code(402);
    echo json_encode(['status' => 'error', 'message' => 'The Live Classes add-on is not active for this school.']);
    exit;
}

$staff_id = (int) ($_SESSION['staff_id'] ?? 0);
$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$question_id = (int) ($input['question_id'] ?? 0);
$answer_text = trim((string) ($input['answer_text'] ?? ''));

if ($answer_text === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error']);
    exit;
}

$verify = $pdo->prepare(
    "SELECT lq.id FROM ilearning_live_questions lq
     JOIN ilearning_live_sessions ls ON ls.id = lq.session_id
     WHERE lq.id = ? AND ls.school_id = ? AND ls.teacher_id = ?"
);
$verify->execute([$question_id, $school_id, $staff_id]);

if (!$verify->fetch()) {
    http_response_code(403);
    echo json_encode(['status' => 'error']);
    exit;
}

$pdo->prepare("UPDATE ilearning_live_questions SET answer_text = ?, answered_by = ?, answered_at = NOW(), status = 'answered' WHERE id = ?")
    ->execute([$answer_text, $staff_id, $question_id]);

echo json_encode(['status' => 'ok']);

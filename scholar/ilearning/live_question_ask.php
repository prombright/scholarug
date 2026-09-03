<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);

header('Content-Type: application/json');

$school_id = current_school_id();
$student_id = current_student_id();
$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$session_id = (int) ($input['session_id'] ?? 0);
$question_text = trim((string) ($input['question_text'] ?? ''));
$visibility = in_array($input['visibility'] ?? '', ['public', 'private'], true) ? $input['visibility'] : 'public';

if ($question_text === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error']);
    exit;
}

$stmt = $pdo->prepare('SELECT class_id FROM ilearning_live_sessions WHERE id = ? AND school_id = ?');
$stmt->execute([$session_id, $school_id]);
$classId = $stmt->fetchColumn();

$stuStmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
$stuStmt->execute([$student_id, $school_id]);

if ($classId === false || (int) $stuStmt->fetchColumn() !== (int) $classId) {
    http_response_code(403);
    echo json_encode(['status' => 'error']);
    exit;
}

$pdo->prepare('INSERT INTO ilearning_live_questions (session_id, student_id, question_text, visibility) VALUES (?, ?, ?, ?)')
    ->execute([$session_id, $student_id, $question_text, $visibility]);

echo json_encode(['status' => 'ok']);

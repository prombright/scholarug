<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_ilearning_helpers.php';

header('Content-Type: application/json');

$school_id = current_school_id();
$student_id = current_student_id();

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$topic_id = (int) ($input['topic_id'] ?? 0);
$percent = max(0, min(100, (int) ($input['percent'] ?? 0)));
$deltaSeconds = max(0, min(120, (int) ($input['delta_seconds'] ?? 0))); // clamp -- one ping interval's worth, never trust a large client-supplied value

$stmt = $pdo->prepare("SELECT class_id FROM ilearning_topics WHERE id = ? AND school_id = ? AND status = 'Published'");
$stmt->execute([$topic_id, $school_id]);
$classId = $stmt->fetchColumn();

if ($classId === false || !ilearning_student_in_class($pdo, $school_id, $student_id, (int) $classId)) {
    http_response_code(403);
    echo json_encode(['status' => 'error']);
    exit;
}

// Idempotent upsert -- same UNIQUE + ON DUPLICATE KEY UPDATE idiom as
// student_marks. percent_complete is monotonic (GREATEST) so a stale ping
// arriving after a later one can never regress the recorded progress, and
// time only accumulates for intervals the page was actually visible.
$pdo->prepare(
    "INSERT INTO ilearning_progress (school_id, student_id, topic_id, opened_at, last_ping_at, percent_complete, time_spent_seconds, status)
     VALUES (?, ?, ?, NOW(), NOW(), ?, ?, IF(? >= 90, 'completed', 'in_progress'))
     ON DUPLICATE KEY UPDATE
        last_ping_at = NOW(),
        percent_complete = GREATEST(percent_complete, VALUES(percent_complete)),
        time_spent_seconds = time_spent_seconds + VALUES(time_spent_seconds),
        -- completed_at must be computed BEFORE status is reassigned below --
        -- MySQL evaluates SET clauses left-to-right and a later clause sees
        -- the NEW value of a column a prior clause already assigned, so
        -- checking `status <> 'completed'` after status has just been set
        -- to 'completed' would always be false and completed_at would
        -- never get stamped.
        completed_at = IF(status <> 'completed' AND VALUES(percent_complete) >= 90, NOW(), completed_at),
        status = IF(VALUES(percent_complete) >= 90, 'completed', status)"
)->execute([$school_id, $student_id, $topic_id, $percent, $deltaSeconds, $percent]);

echo json_encode(['status' => 'ok']);

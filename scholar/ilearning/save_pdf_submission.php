<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| iLEARNING — SAVE A PDF ACTIVITY ANSWER
|--------------------------------------------------------------------------
| Called via fetch() from view_topic.php's answer form -- JSON response,
| no page reload/redirect. Once a submission row exists for a student on
| this topic it's locked: the activity "closes" on submit (view_topic.php
| stops rendering the editable form entirely once $submission is non-null,
| and this endpoint refuses a second insert even if someone posts directly
| to it), instead of the previous silent-overwrite-on-resubmit behavior.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_ilearning_helpers.php';

header('Content-Type: application/json');

$school_id = current_school_id();
$student_id = current_student_id();

$topic_id = (int) ($_POST['topic_id'] ?? 0);
$answer_text = trim($_POST['answer_text'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM ilearning_topics WHERE id = ? AND school_id = ? AND status = 'Published' AND content_type = 'pdf_activity'");
$stmt->execute([$topic_id, $school_id]);
$topic = $stmt->fetch();

if (!$topic || !ilearning_student_in_class($pdo, $school_id, $student_id, (int) $topic['class_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'This activity is not available to you.']);
    exit;
}

if ($answer_text === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please write an answer before submitting.']);
    exit;
}

$existing = $pdo->prepare('SELECT id FROM ilearning_pdf_submissions WHERE topic_id = ? AND student_id = ?');
$existing->execute([$topic_id, $student_id]);
if ($existing->fetch()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'You have already submitted this activity.']);
    exit;
}

try {
    $pdo->prepare(
        'INSERT INTO ilearning_pdf_submissions (school_id, topic_id, student_id, answer_text) VALUES (?, ?, ?, ?)'
    )->execute([$school_id, $topic_id, $student_id, $answer_text]);
} catch (\PDOException $e) {
    // UNIQUE(topic_id, student_id) catching a genuine race (two submits
    // landing at once) -- same end result as the pre-check above finding
    // it, just for the narrower window between that check and this insert.
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'You have already submitted this activity.']);
    exit;
}

echo json_encode(['ok' => true]);
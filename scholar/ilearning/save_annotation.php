<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| iLEARNING — SAVE A TEXT MARK
|--------------------------------------------------------------------------
| Called via fetch() by assets/js/ilearning-annotate.js after a teacher
| selects a range of a student's submitted text and picks a mark type.
| Re-derives ownership from the database on every call (source_type +
| source_id -> the owning topic's teacher_id) rather than trusting
| anything about "which teacher's screen this came from" -- same
| ownership-recheck convention every other teacher-side mutation in this
| module already follows (see grade_open_answers.php's $verify query).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/_ilearning_helpers.php';

header('Content-Type: application/json');

$school_id = current_school_id();
$staff_id = (int) ($_SESSION['staff_id'] ?? 0);

$source_type = $_POST['source_type'] ?? '';
$source_id = (int) ($_POST['source_id'] ?? 0);
$start = (int) ($_POST['start_offset'] ?? -1);
$end = (int) ($_POST['end_offset'] ?? -1);
$mark_type = $_POST['mark_type'] ?? 'note';
$comment = trim((string) ($_POST['comment'] ?? ''));

if (!in_array($source_type, ['pdf_submission', 'open_answer'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid source type.']);
    exit;
}
if (!in_array($mark_type, ['correct', 'incorrect', 'note'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid mark type.']);
    exit;
}
if ($start < 0 || $end <= $start) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid selection range.']);
    exit;
}
if (mb_strlen($comment, 'UTF-8') > 500) {
    $comment = mb_substr($comment, 0, 500, 'UTF-8');
}

// Resolve the submitted text's own length (to reject an out-of-range
// selection) AND confirm this teacher actually authored the topic the
// submission belongs to, in one query per source type.
if ($source_type === 'pdf_submission') {
    $stmt = $pdo->prepare(
        "SELECT ps.answer_text FROM ilearning_pdf_submissions ps
         JOIN ilearning_topics t ON t.id = ps.topic_id
         WHERE ps.id = ? AND t.school_id = ? AND t.teacher_id = ?"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT aa.free_text_answer AS answer_text FROM ilearning_attempt_answers aa
         JOIN ilearning_attempts att ON att.id = aa.attempt_id
         JOIN ilearning_topics t ON t.id = att.topic_id
         WHERE aa.id = ? AND t.school_id = ? AND t.teacher_id = ?"
    );
}
$stmt->execute([$source_id, $school_id, $staff_id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Submission not found, or you are not its topic\'s author.']);
    exit;
}

$textLen = mb_strlen((string) $row['answer_text'], 'UTF-8');
if ($end > $textLen) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Selection is out of range for the current text.']);
    exit;
}

$insert = $pdo->prepare(
    'INSERT INTO ilearning_text_annotations (school_id, source_type, source_id, start_offset, end_offset, mark_type, comment, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$insert->execute([$school_id, $source_type, $source_id, $start, $end, $mark_type, $comment !== '' ? $comment : null, $staff_id]);
// Captured immediately after the insert -- a driver-level "last insert id
// on this connection" value that a subsequent SELECT (the annotations
// re-fetch below) was empirically found to reset back to 0 if read after.
$newId = (int) $pdo->lastInsertId();

// Returning the freshly-rendered HTML (instead of just an id) lets the
// calling JS swap the marked-up text back in directly -- no full page
// reload just to see the mark that was, seconds ago, sitting live on
// screen mid-selection.
$html = ilearning_render_annotated_text((string) $row['answer_text'], ilearning_fetch_annotations($pdo, $source_type, $source_id));
echo json_encode(['ok' => true, 'id' => $newId, 'html' => $html]);
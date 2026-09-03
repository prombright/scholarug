<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| iLEARNING — DELETE A TEXT MARK
|--------------------------------------------------------------------------
| created_by is enough of an ownership check on its own here -- a topic
| has exactly one teacher_id owner (ilearning_topics), and only that
| teacher's session can ever reach the save_annotation.php call that
| creates one, so created_by = current staff already implies "this
| teacher's own topic" without a second join back through the topic.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/_ilearning_helpers.php';

header('Content-Type: application/json');

$staff_id = (int) ($_SESSION['staff_id'] ?? 0);
$school_id = current_school_id();
$id = (int) ($_POST['id'] ?? 0);
$source_type = $_POST['source_type'] ?? '';
$source_id = (int) ($_POST['source_id'] ?? 0);

$stmt = $pdo->prepare('DELETE FROM ilearning_text_annotations WHERE id = ? AND school_id = ? AND created_by = ?');
$stmt->execute([$id, $school_id, $staff_id]);

if ($stmt->rowCount() !== 1 || !in_array($source_type, ['pdf_submission', 'open_answer'], true)) {
    echo json_encode(['ok' => false]);
    exit;
}

if ($source_type === 'pdf_submission') {
    $textStmt = $pdo->prepare(
        "SELECT ps.answer_text FROM ilearning_pdf_submissions ps
         JOIN ilearning_topics t ON t.id = ps.topic_id
         WHERE ps.id = ? AND t.school_id = ? AND t.teacher_id = ?"
    );
} else {
    $textStmt = $pdo->prepare(
        "SELECT aa.free_text_answer AS answer_text FROM ilearning_attempt_answers aa
         JOIN ilearning_attempts att ON att.id = aa.attempt_id
         JOIN ilearning_topics t ON t.id = att.topic_id
         WHERE aa.id = ? AND t.school_id = ? AND t.teacher_id = ?"
    );
}
$textStmt->execute([$source_id, $school_id, $staff_id]);
$row = $textStmt->fetch();

$html = $row ? ilearning_render_annotated_text((string) $row['answer_text'], ilearning_fetch_annotations($pdo, $source_type, $source_id)) : '';
echo json_encode(['ok' => true, 'html' => $html]);
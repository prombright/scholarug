<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| iLEARNING — SHARED HELPERS
|--------------------------------------------------------------------------
| Included by every ilearning/*.php page after auth_guard.php + db.php.
|--------------------------------------------------------------------------
*/

/**
 * Escapes $text, then turns markdown-style bold (two asterisks) and
 * italic (one asterisk) into &lt;strong&gt;/&lt;em&gt;
 * -- the display half of assets/js/rich-toolbar.js's plain-text-with-
 * markdown-syntax editing, safe by construction: htmlspecialchars() runs
 * FIRST, so the only tags that can ever appear in the output are the two
 * this function deliberately inserts -- there is no path for a browser
 * to get raw HTML into the page through this, unlike a contenteditable/
 * stored-HTML rich text editor, which is why this app uses markdown
 * insertion instead of one.
 *
 * No line-break handling here on purpose -- callers that need it either
 * already have `white-space:pre-wrap` CSS (ilearning_render_annotated_text()'s
 * callers) or want block-level markdown too, see scholar_render_rich_text().
 */
function scholar_render_rich_text_inline(string $text): string
{
    $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);
    return $html;
}

/**
 * scholar_render_rich_text_inline() plus block-level "- " bullet / "1. "
 * numbered lists and newline-to-<br> conversion -- for standalone content
 * with no other renderer already handling its line breaks (topic bodies,
 * question text, model answers). NOT used for annotated submission text
 * (see ilearning_render_annotated_text()) -- a <mark> boundary landing
 * mid-list-item would be a real rendering conflict this deliberately
 * avoids by keeping annotated text to inline-only formatting.
 */
function scholar_render_rich_text(string $text): string
{
    $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);

    $out = [];
    $listType = null;
    foreach (explode("\n", $html) as $line) {
        if (preg_match('/^-\s+(.*)$/', $line, $m)) {
            if ($listType !== 'ul') {
                if ($listType) $out[] = "</{$listType}>";
                $out[] = '<ul>';
                $listType = 'ul';
            }
            $out[] = '<li>' . $m[1] . '</li>';
        } elseif (preg_match('/^\d+\.\s+(.*)$/', $line, $m)) {
            if ($listType !== 'ol') {
                if ($listType) $out[] = "</{$listType}>";
                $out[] = '<ol>';
                $listType = 'ol';
            }
            $out[] = '<li>' . $m[1] . '</li>';
        } else {
            if ($listType) {
                $out[] = "</{$listType}>";
                $listType = null;
            }
            $out[] = $line . '<br>';
        }
    }
    if ($listType) {
        $out[] = "</{$listType}>";
    }

    return implode('', $out);
}

/**
 * "Is this teacher allowed to touch this class+subject" -- the same check
 * teachers_portal.php already does before accepting a marks submission
 * (teachers_portal.php:23-27). Every iLearning teacher-side action
 * (create topic, add question, grade answers, schedule a live session)
 * must pass this before doing anything. Deliberately does NOT fall back
 * to the legacy staff.assign_subject free-text column -- assign_teacher.php's
 * own header comment already flags that column as fragile/superseded.
 */
function ilearning_teacher_authorized(PDO $pdo, int $schoolId, int $teacherId, int $classId, int $subjectId): bool
{
    $stmt = $pdo->prepare(
        'SELECT id FROM teacher_assignments WHERE school_id = ? AND teacher_id = ? AND class_id = ? AND subject_id = ?'
    );
    $stmt->execute([$schoolId, $teacherId, $classId, $subjectId]);
    return (bool) $stmt->fetchColumn();
}

/** Is $studentId (per session) actually enrolled in $classId at $schoolId? */
function ilearning_student_in_class(PDO $pdo, int $schoolId, int $studentId, int $classId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM students WHERE id = ? AND school_id = ? AND class_id = ?');
    $stmt->execute([$studentId, $schoolId, $classId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Saves an uploaded note/document attachment. Same convention as
 * staff_manager.php's photo upload (self-healing mkdir, prefixed
 * collision-safe filename, extension allow-list) extended to document
 * types since no existing upload path handles anything but images/CSV.
 *
 * @return array{path:string,original_name:string,ext:string}|null null on
 *         any validation failure (caller decides how to surface the error)
 */
function ilearning_save_attachment(array $file, int $topicId): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }

    $dir = __DIR__ . '/../uploads/ilearning';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'topic_' . $topicId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    $destPath = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    return [
        'path' => 'uploads/ilearning/' . $filename,
        'original_name' => $file['name'],
        'ext' => $ext,
    ];
}

/**
 * Saves an uploaded PDF for a pdf_notes/pdf_activity topic into
 * uploads/ilearning_private/ instead of the public uploads/ilearning/ tree
 * -- that directory has a .htaccess denying all direct HTTP access, so the
 * only way to ever read the file back is through serve_pdf.php's auth gate
 * (see that file for the student/teacher access check). Every other
 * upload in this app (staff photos, this module's own generic
 * attachments) is a plain public file with a guessable/copyable URL; this
 * is the one exception, because "students cannot download this PDF" is
 * meaningless if it's sitting in a public folder regardless.
 *
 * @return array{path:string,original_name:string,ext:string}|null null on
 *         any validation failure (caller decides how to surface the error)
 */
function ilearning_save_pdf_attachment(array $file, int $topicId): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return null;
    }

    // Extension allow-listing alone trusts the client-supplied filename --
    // a light magic-byte check (every real PDF starts with "%PDF-") costs
    // nothing and catches a renamed non-PDF before it ever reaches disk.
    $handle = fopen($file['tmp_name'], 'rb');
    $header = $handle ? fread($handle, 5) : '';
    if ($handle) fclose($handle);
    if ($header !== '%PDF-') {
        return null;
    }

    $dir = __DIR__ . '/../uploads/ilearning_private';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'topic_' . $topicId . '_' . time() . '_' . rand(1000, 9999) . '.pdf';
    $destPath = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    return [
        'path' => 'uploads/ilearning_private/' . $filename,
        'original_name' => $file['name'],
        'ext' => 'pdf',
    ];
}

/**
 * Shared access check for serve_pdf.php (and anything else that needs to
 * gate a topic's files): true if the current session is either the
 * teacher who authored $topic, or a student enrolled in its class.
 * Does NOT check topic status -- a teacher must be able to preview their
 * own Draft topic's PDF, so that's left to the caller if it matters.
 */
function ilearning_can_access_topic(PDO $pdo, array $topic, string $role, int $schoolId, ?int $teacherId, ?int $studentId): bool
{
    if ($role === 'teacher' && $teacherId !== null) {
        return (int) $topic['teacher_id'] === $teacherId && (int) $topic['school_id'] === $schoolId;
    }
    if ($role === 'student' && $studentId !== null) {
        return (int) $topic['school_id'] === $schoolId
            && $topic['status'] === 'Published'
            && ilearning_student_in_class($pdo, $schoolId, $studentId, (int) $topic['class_id']);
    }
    return false;
}

/**
 * If every answer in $attemptId now has a non-NULL points_awarded (MCQ
 * auto-scored, AI-graded, or a teacher's manual score), sums them, marks
 * the attempt 'graded', and -- if its topic links to an admin `assessments`
 * row -- upserts student_marks using the EXACT insert shape already proven
 * in teachers_portal.php:32-39, so generate_report.php needs zero changes
 * to pick this mark up. Safe to call after every single answer is graded;
 * it's a no-op (returns false) until the last answer in the attempt lands.
 *
 * @return bool true if the attempt was actually finalized by this call
 */
function ilearning_try_finalize_attempt(PDO $pdo, int $attemptId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM ilearning_attempts WHERE id = ?');
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
    if (!$attempt || $attempt['status'] === 'graded') {
        return false;
    }

    $ansStmt = $pdo->prepare('SELECT points_awarded FROM ilearning_attempt_answers WHERE attempt_id = ?');
    $ansStmt->execute([$attemptId]);
    $answers = $ansStmt->fetchAll();

    foreach ($answers as $a) {
        if ($a['points_awarded'] === null) {
            return false; // still something waiting on a human or a retried AI grade
        }
    }

    $total = array_sum(array_map(static fn($a) => (float) $a['points_awarded'], $answers));

    $pdo->prepare("UPDATE ilearning_attempts SET status = 'graded', score_percentage = ?, submitted_at = COALESCE(submitted_at, NOW()) WHERE id = ?")
        ->execute([$total, $attemptId]);

    if ($attempt['topic_id'] === null) {
        return true; // standing practice -- never touches student_marks
    }

    $topicStmt = $pdo->prepare('SELECT school_id, class_id, subject_id, teacher_id, assessment_id FROM ilearning_topics WHERE id = ?');
    $topicStmt->execute([$attempt['topic_id']]);
    $topic = $topicStmt->fetch();

    if ($topic === false || $topic['assessment_id'] === null) {
        return true; // practice-only topic, no assessment attached
    }

    $pdo->prepare(
        'INSERT INTO student_marks (school_id, student_id, class_id, subject_id, teacher_id, assessment_id, marks)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE marks = VALUES(marks), teacher_id = VALUES(teacher_id), updated_at = NOW()'
    )->execute([
        $topic['school_id'], $attempt['student_id'], $topic['class_id'], $topic['subject_id'],
        $topic['teacher_id'], $topic['assessment_id'], $total,
    ]);

    return true;
}

/**
 * Every marked range for one submission, ordered left-to-right so the
 * renderer below can walk the text once. $sourceType/$sourceId identify
 * which of the two submission tables the marked text lives in -- see
 * ilearning_annotations_migration.sql's header for why there's no single
 * `submissions` table to hang a normal FK off of.
 *
 * @return array<int,array{id:int,start_offset:int,end_offset:int,mark_type:string,comment:?string}>
 */
function ilearning_fetch_annotations(PDO $pdo, string $sourceType, int $sourceId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, start_offset, end_offset, mark_type, comment FROM ilearning_text_annotations
         WHERE source_type = ? AND source_id = ? ORDER BY start_offset ASC'
    );
    $stmt->execute([$sourceType, $sourceId]);
    return $stmt->fetchAll();
}

/**
 * Renders a student's plain-text submission as HTML, wrapping each marked
 * range in a <mark> carrying its type/comment/id as data attributes --
 * assets/js/ilearning-annotate.js reads those to show the comment on
 * hover/click and to wire up the delete (x) control, no extra request
 * needed just to display what's already been marked.
 *
 * Offsets are counted in Unicode codepoints (mb_* functions, UTF-8) to
 * match how the browser's Selection API counts JS string characters for
 * the overwhelming majority of real text -- the one known gap is
 * characters outside the Basic Multilingual Plane (rare emoji, some
 * historic scripts), which JS counts as 2 UTF-16 units but this counts as
 * 1 codepoint; not expected in school text submissions, not worth the
 * extra complexity of a UTF-16-aware offset scheme for that edge case.
 *
 * Malformed/out-of-range/overlapping annotations (offsets past the text's
 * own length, or a range that starts before the previous one ended) are
 * skipped rather than corrupting the render -- can happen if the
 * underlying text was ever edited after marking.
 *
 * @param array<int,array{id:int,start_offset:int,end_offset:int,mark_type:string,comment:?string}> $annotations
 */
function ilearning_render_annotated_text(string $text, array $annotations): string
{
    $len = mb_strlen($text, 'UTF-8');
    $html = '';
    $cursor = 0;

    foreach ($annotations as $a) {
        $start = (int) $a['start_offset'];
        $end = (int) $a['end_offset'];
        if ($start < $cursor || $end <= $start || $end > $len) {
            continue; // out of order, empty, or stale relative to edited text
        }

        $html .= htmlspecialchars(mb_substr($text, $cursor, $start - $cursor, 'UTF-8'), ENT_QUOTES, 'UTF-8');

        $markClass = 'ilearn-mark-' . preg_replace('/[^a-z]/', '', $a['mark_type']);
        $html .= '<mark class="ilearn-mark ' . $markClass . '"'
            . ' data-annotation-id="' . (int) $a['id'] . '"'
            . ' data-comment="' . htmlspecialchars((string) ($a['comment'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
            . '>'
            . htmlspecialchars(mb_substr($text, $start, $end - $start, 'UTF-8'), ENT_QUOTES, 'UTF-8')
            . '</mark>';

        $cursor = $end;
    }

    $html .= htmlspecialchars(mb_substr($text, $cursor, null, 'UTF-8'), ENT_QUOTES, 'UTF-8');

    return $html;
}

/**
 * Grade one student's answer to a pdf_activity topic -- the counterpart to
 * ilearning_try_finalize_attempt() above, for the PDF-activity pathway
 * (ilearning_pdf_submissions) instead of the MCQ/assessment pathway
 * (ilearning_attempts). No attempt row exists here at all, so this reads
 * everything it needs straight off ilearning_topics and writes into
 * student_marks the same way -- same natural key
 * (student_id, subject_id, assessment_id, paper_number), same
 * ON DUPLICATE KEY UPDATE, so the two pathways can never double-count a
 * subject/assessment as long as topics keep distinct assessment_ids per
 * real exam vs. per PDF activity.
 *
 * @return array{ok: bool, error?: string}
 */
function ilearning_grade_pdf_submission(PDO $pdo, int $topicId, int $studentId, int $teacherId, int $marks): array
{
    $stmt = $pdo->prepare("
        SELECT school_id, class_id, subject_id, assessment_id
        FROM ilearning_topics
        WHERE id = ? AND teacher_id = ? AND content_type = 'pdf_activity'
    ");
    $stmt->execute([$topicId, $teacherId]);
    $topic = $stmt->fetch();

    if (!$topic) {
        return ['ok' => false, 'error' => 'Topic not found, or you are not its author.'];
    }

    if ($topic['assessment_id'] === null) {
        return ['ok' => false, 'error' => 'This topic isn\'t linked to an assessment yet -- edit the topic and pick one under "Assessment" before grading.'];
    }

    if (!ilearning_student_in_class($pdo, (int) $topic['school_id'], $studentId, (int) $topic['class_id'])) {
        return ['ok' => false, 'error' => 'That student is not in this topic\'s class.'];
    }

    $marks = max(0, min(100, $marks));

    $pdo->prepare(
        'INSERT INTO student_marks (school_id, student_id, class_id, subject_id, teacher_id, assessment_id, marks)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE marks = VALUES(marks), teacher_id = VALUES(teacher_id), updated_at = NOW()'
    )->execute([
        $topic['school_id'], $studentId, $topic['class_id'], $topic['subject_id'],
        $teacherId, $topic['assessment_id'], $marks,
    ]);

    return ['ok' => true];
}

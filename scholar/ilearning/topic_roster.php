<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/_ilearning_helpers.php';

$school_id = current_school_id();
$staff_id = (int) ($_SESSION['staff_id'] ?? 0);
$topic_id = (int) ($_GET['topic_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM ilearning_topics WHERE id = ? AND school_id = ? AND teacher_id = ?');
$stmt->execute([$topic_id, $school_id, $staff_id]);
$topic = $stmt->fetch();
if (!$topic) {
    http_response_code(403);
    die('Topic not found, or you are not its author.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_feedback'])) {
    $student_id = (int) ($_POST['student_id'] ?? 0);
    $note = trim($_POST['feedback_note'] ?? '');
    $pdo->prepare(
        'INSERT INTO ilearning_progress (school_id, student_id, topic_id, feedback_note, feedback_by, feedback_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE feedback_note = VALUES(feedback_note), feedback_by = VALUES(feedback_by), feedback_at = NOW()'
    )->execute([$school_id, $student_id, $topic_id, $note, $staff_id]);
    header('Location: topic_roster.php?topic_id=' . $topic_id);
    exit;
}

$grade_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_pdf_submission'])) {
    $student_id = (int) ($_POST['student_id'] ?? 0);
    $score = (int) ($_POST['score'] ?? 0);
    $result = ilearning_grade_pdf_submission($pdo, $topic_id, $student_id, $staff_id, $score);
    if ($result['ok']) {
        header('Location: topic_roster.php?topic_id=' . $topic_id . '&graded=1');
        exit;
    }
    $grade_error = $result['error'];
}

$roster_stmt = $pdo->prepare("
    SELECT st.id AS student_id, st.full_name, st.student_no,
           p.percent_complete, p.status, p.time_spent_seconds, p.opened_at, p.feedback_note
    FROM students st
    LEFT JOIN ilearning_progress p ON p.student_id = st.id AND p.topic_id = ?
    WHERE st.school_id = ? AND st.class_id = ?
    ORDER BY st.full_name
");
$roster_stmt->execute([$topic_id, $school_id, $topic['class_id']]);
$roster = $roster_stmt->fetchAll();

$targetDays = (int) ($topic['target_days_to_complete'] ?? 0);
$isPdfActivity = $topic['content_type'] === 'pdf_activity';

// Per-student answer text, gradeable via ilearning_grade_pdf_submission()
// (writes into student_marks the same way the MCQ/assessment pathway does)
// when the topic has an assessment_id attached -- see that function's
// docblock for why an assessment_id is required.
$submissions = [];
$pdf_marks = [];
if ($isPdfActivity) {
    $sub_stmt = $pdo->prepare('SELECT id, student_id, answer_text, submitted_at FROM ilearning_pdf_submissions WHERE topic_id = ?');
    $sub_stmt->execute([$topic_id]);
    foreach ($sub_stmt->fetchAll() as $s) {
        $submissions[(int) $s['student_id']] = $s;
    }

    if ($topic['assessment_id'] !== null) {
        $marks_stmt = $pdo->prepare('SELECT student_id, marks FROM student_marks WHERE subject_id = ? AND assessment_id = ? AND paper_number = 1');
        $marks_stmt->execute([$topic['subject_id'], $topic['assessment_id']]);
        foreach ($marks_stmt->fetchAll() as $m) {
            $pdf_marks[(int) $m['student_id']] = (int) $m['marks'];
        }
    }
}

$class_teacher_stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND class_teacher_id = ?");
$class_teacher_stmt->execute([$school_id, $staff_id]);
$is_any_class_teacher = (int) $class_teacher_stmt->fetchColumn() > 0;

$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.teacher_id = ? AND cv.school_id = ? AND cm.sender_role = 'student' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$staff_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'ilearning';
require_once __DIR__ . '/../_teacher_shell.php';
?>
<style>
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
th,td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;background:rgba(255,255,255,0.02);}
.bar-bg{background:#111826;border-radius:6px;overflow:hidden;width:120px;height:10px;}
.bar-fill{height:100%;background:var(--cyan);}
.pill{font-size:0.7rem;padding:2px 8px;border-radius:20px;font-weight:700;}
.pill-ahead{background:rgba(16,185,129,0.15);color:var(--green);}
.pill-behind{background:rgba(239,68,68,0.12);color:var(--danger);}
.pill-none{background:rgba(100,116,139,0.15);color:var(--muted);}
input[type=text]{background:#111826;border:1px solid var(--border);color:var(--text);padding:6px;border-radius:4px;font-size:0.8rem;width:180px;}
button{cursor:pointer;border:none;border-radius:4px;padding:6px 10px;font-size:0.75rem;font-weight:700;background:var(--cyan);color:#04121a;}
<?php include __DIR__ . '/_ilearning_annotate_style.php'; ?>
</style>
<div class="page-title-row">
    <h1>Progress — <?= htmlspecialchars($topic['title'], ENT_QUOTES, 'UTF-8') ?></h1>
</div>

<?php if ($grade_error): ?>
    <div class="alert alert-danger" style="padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(239,68,68,0.12);color:var(--danger);"><?= htmlspecialchars($grade_error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (isset($_GET['graded'])): ?>
    <div class="alert alert-success" style="padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(16,185,129,0.12);color:var(--green);">Grade saved.</div>
<?php endif; ?>
<?php if ($isPdfActivity && $topic['assessment_id'] === null): ?>
    <div class="alert" style="padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(245,158,11,0.12);color:var(--amber);">This topic isn't linked to an assessment yet, so grades entered here won't reach report cards. <a href="topic_edit.php?id=<?= $topic_id ?>" style="color:inherit;text-decoration:underline;">Edit the topic</a> and pick one under "Assessment" first.</div>
<?php endif; ?>

    <div class="section">
        <table>
            <thead><tr><th>Student</th><th>Progress</th><th>Pace</th><th>Time Spent</th><?php if ($isPdfActivity): ?><th>Answer</th><?php endif; ?><th>Feedback</th></tr></thead>
            <tbody>
            <?php foreach ($roster as $r): ?>
                <?php
                $percent = (int) ($r['percent_complete'] ?? 0);
                $daysOpen = $r['opened_at'] ? (int) ((time() - strtotime($r['opened_at'])) / 86400) : null;
                $expectedPercent = ($targetDays > 0 && $daysOpen !== null) ? min(100, (int) round(($daysOpen / $targetDays) * 100)) : null;
                $paceClass = 'pill-none';
                $paceLabel = 'Not started';
                if ($r['opened_at']) {
                    if ($expectedPercent === null) {
                        $paceLabel = 'No target set';
                    } elseif ($percent >= $expectedPercent) {
                        $paceClass = 'pill-ahead'; $paceLabel = 'On pace';
                    } else {
                        $paceClass = 'pill-behind'; $paceLabel = 'Behind';
                    }
                }
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8') ?><br><span style="color:var(--muted);font-size:0.75rem;"><?= htmlspecialchars($r['student_no'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td>
                        <div class="bar-bg"><div class="bar-fill" style="width:<?= $percent ?>%;"></div></div>
                        <span style="font-size:0.75rem;color:var(--muted);"><?= $percent ?>%</span>
                    </td>
                    <td><span class="pill <?= $paceClass ?>"><?= $paceLabel ?></span></td>
                    <td><?= $r['time_spent_seconds'] ? gmdate('H:i:s', (int) $r['time_spent_seconds']) : '—' ?></td>
                    <?php if ($isPdfActivity): ?>
                    <td>
                        <?php $sub = $submissions[(int) $r['student_id']] ?? null; ?>
                        <?php if ($sub): ?>
                            <details>
                                <summary style="cursor:pointer;color:var(--cyan);font-size:0.8rem;">Submitted <?= htmlspecialchars(date('d M, H:i', strtotime($sub['submitted_at'])), ENT_QUOTES, 'UTF-8') ?></summary>
                                <div class="ilearn-annotatable" data-source-type="pdf_submission" data-source-id="<?= (int) $sub['id'] ?>" style="margin-top:8px;max-width:280px;font-size:0.8rem;color:var(--text);"><?= ilearning_render_annotated_text($sub['answer_text'], ilearning_fetch_annotations($pdo, 'pdf_submission', (int) $sub['id'])) ?></div>
                            </details>
                            <?php if ($topic['assessment_id'] !== null): ?>
                                <?php $existing_mark = $pdf_marks[(int) $r['student_id']] ?? null; ?>
                                <form method="POST" style="display:flex;gap:6px;align-items:center;margin-top:6px;">
                                    <input type="hidden" name="student_id" value="<?= (int) $r['student_id'] ?>">
                                    <input type="number" name="score" min="0" max="100" style="width:60px;" value="<?= $existing_mark !== null ? $existing_mark : '' ?>" placeholder="0-100" required>
                                    <button type="submit" name="grade_pdf_submission"><?= $existing_mark !== null ? 'Update' : 'Grade' ?></button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--muted);font-size:0.8rem;">Not yet</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <form method="POST" style="display:flex;gap:6px;">
                            <input type="hidden" name="student_id" value="<?= (int) $r['student_id'] ?>">
                            <input type="text" name="feedback_note" value="<?= htmlspecialchars((string) ($r['feedback_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="One-line note...">
                            <button type="submit" name="save_feedback">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
        </div>
    </div>
</div>
<?php if ($isPdfActivity): ?>
<script src="../assets/js/ilearning-annotate.js"></script>
<?php endif; ?>
</body>
</html>

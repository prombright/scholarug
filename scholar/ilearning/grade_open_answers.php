<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/_ilearning_helpers.php';

$school_id = current_school_id();
$staff_id = (int) ($_SESSION['staff_id'] ?? 0);
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_answer'])) {
    $answer_id = (int) ($_POST['answer_id'] ?? 0);
    $score = max(0, min(100, (int) ($_POST['score'] ?? 0)));
    $feedback = trim($_POST['teacher_feedback'] ?? '');

    // Re-verify this answer really belongs to one of this teacher's own
    // topics before touching it -- same ownership check every other
    // teacher-side mutation in this module does.
    $verify = $pdo->prepare(
        "SELECT aa.id, aa.attempt_id FROM ilearning_attempt_answers aa
         JOIN ilearning_attempts att ON att.id = aa.attempt_id
         JOIN ilearning_topics t ON t.id = att.topic_id
         WHERE aa.id = ? AND t.school_id = ? AND t.teacher_id = ?"
    );
    $verify->execute([$answer_id, $school_id, $staff_id]);
    $row = $verify->fetch();

    if ($row) {
        $pdo->prepare(
            "UPDATE ilearning_attempt_answers
             SET points_awarded = ?, teacher_feedback = ?, graded_by = ?, graded_by_ai = 0, graded_at = NOW()
             WHERE id = ?"
        )->execute([$score, $feedback ?: null, $staff_id, $answer_id]);

        ilearning_try_finalize_attempt($pdo, (int) $row['attempt_id']);
        $success = 'Grade saved.';
    }
}

$queue_stmt = $pdo->prepare("
    SELECT aa.id AS answer_id, aa.free_text_answer, aa.points_awarded, aa.graded_by_ai, aa.teacher_feedback,
           q.question_text, q.model_answer, q.grading_rubric,
           st.full_name, t.title AS topic_title, att.submitted_at
    FROM ilearning_attempt_answers aa
    JOIN ilearning_questions q ON q.id = aa.question_id
    JOIN ilearning_attempts att ON att.id = aa.attempt_id
    JOIN students st ON st.id = att.student_id
    JOIN ilearning_topics t ON t.id = att.topic_id
    WHERE q.question_type = 'short_answer' AND t.school_id = ? AND t.teacher_id = ? AND aa.graded_by IS NULL
    ORDER BY att.submitted_at ASC
");
$queue_stmt->execute([$school_id, $staff_id]);
$queue = $queue_stmt->fetchAll();

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
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:16px;}
.meta{color:var(--muted);font-size:0.75rem;margin-bottom:10px;}
.qtext{font-weight:600;margin-bottom:8px;}
.block{background:#111826;border-radius:6px;padding:10px;margin-bottom:8px;font-size:0.85rem;}
.block .label{font-size:0.7rem;text-transform:uppercase;color:var(--muted);margin-bottom:4px;}
.ai-badge{display:inline-block;background:rgba(0,168,168,0.15);color:var(--cyan);font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;}
.gradeform{display:flex;gap:8px;align-items:flex-end;margin-top:10px;}
input[type=number]{width:80px;background:#111826;border:1px solid var(--border);color:var(--text);padding:8px;border-radius:6px;}
input[type=text]{flex:1;background:#111826;border:1px solid var(--border);color:var(--text);padding:8px;border-radius:6px;}
label{display:block;font-size:0.7rem;color:var(--muted);margin-bottom:4px;}
button{cursor:pointer;border:none;border-radius:6px;padding:9px 16px;font-weight:700;font-size:0.8rem;background:var(--cyan);color:#04121a;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.empty{color:var(--muted);text-align:center;padding:30px;}
<?php include __DIR__ . '/_ilearning_annotate_style.php'; ?>
</style>
<div class="page-title-row">
    <h1>Grade Open (Short-Answer) Submissions</h1>
</div>

    <?php if ($success): ?><div class="alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if (empty($queue)): ?>
        <div class="card"><p class="empty">Nothing waiting on you — every short-answer submission is graded.</p></div>
    <?php endif; ?>

    <?php foreach ($queue as $item): ?>
    <div class="card">
        <div class="meta">
            <?= htmlspecialchars($item['full_name'], ENT_QUOTES, 'UTF-8') ?> —
            <?= htmlspecialchars($item['topic_title'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($item['graded_by_ai']): ?><span class="ai-badge">AI-graded, review</span><?php endif; ?>
        </div>
        <div class="qtext"><?= scholar_render_rich_text($item['question_text']) ?></div>
        <div class="block"><div class="label">Model Answer</div><?= scholar_render_rich_text($item['model_answer']) ?></div>
        <div class="block">
            <div class="label">Student's Answer &mdash; select text to mark it</div>
            <div class="ilearn-annotatable" data-source-type="open_answer" data-source-id="<?= (int) $item['answer_id'] ?>"><?= ilearning_render_annotated_text((string) ($item['free_text_answer'] ?? ''), ilearning_fetch_annotations($pdo, 'open_answer', (int) $item['answer_id'])) ?></div>
        </div>
        <?php if ($item['graded_by_ai']): ?>
        <div class="block"><div class="label">AI Score / Feedback</div><?= (int) $item['points_awarded'] ?>/100 — <?= htmlspecialchars((string) $item['teacher_feedback'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" class="gradeform">
            <input type="hidden" name="answer_id" value="<?= (int) $item['answer_id'] ?>">
            <div>
                <label>Score (0-100)</label>
                <input type="number" name="score" min="0" max="100" value="<?= (int) ($item['points_awarded'] ?? 0) ?>" required>
            </div>
            <input type="text" name="teacher_feedback" placeholder="Feedback / correction for the student..." value="<?= htmlspecialchars((string) ($item['graded_by_ai'] ? '' : ($item['teacher_feedback'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" name="grade_answer"><?= $item['graded_by_ai'] ? 'Confirm / Override' : 'Save Grade' ?></button>
        </form>
    </div>
    <?php endforeach; ?>
        </div>
    </div>
</div>
<?php if (!empty($queue)): ?>
<script src="../assets/js/ilearning-annotate.js"></script>
<?php endif; ?>
</body>
</html>

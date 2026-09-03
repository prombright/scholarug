<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_ilearning_helpers.php';
require_once __DIR__ . '/AiGrader.php';

$school_id = current_school_id();
$student_id = current_student_id();
$topic_id = (int) ($_GET['topic_id'] ?? $_POST['topic_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM ilearning_topics WHERE id = ? AND school_id = ? AND status = 'Published'");
$stmt->execute([$topic_id, $school_id]);
$topic = $stmt->fetch();

if (!$topic || $topic['assessment_id'] === null || !ilearning_student_in_class($pdo, $school_id, $student_id, (int) $topic['class_id'])) {
    http_response_code(403);
    die('This assessment is not available to you.');
}

$attStmt = $pdo->prepare("SELECT * FROM ilearning_attempts WHERE student_id = ? AND topic_id = ? AND pool_type='topic_assessment' ORDER BY id DESC LIMIT 1");
$attStmt->execute([$student_id, $topic_id]);
$attempt = $attStmt->fetch();

// --- Handle submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attempt'])) {
    if ($attempt && $attempt['status'] !== 'in_progress') {
        // already submitted -- ignore a resubmit (e.g. back-button + resend)
    } else {
        if (!$attempt) {
            $pdo->prepare("INSERT INTO ilearning_attempts (school_id, student_id, topic_id, pool_type, status) VALUES (?, ?, ?, 'topic_assessment', 'in_progress')")
                ->execute([$school_id, $student_id, $topic_id]);
            $attemptId = (int) $pdo->lastInsertId();
        } else {
            $attemptId = (int) $attempt['id'];
        }

        $qStmt = $pdo->prepare('SELECT * FROM ilearning_questions WHERE topic_id = ? ORDER BY id');
        $qStmt->execute([$topic_id]);
        $questions = $qStmt->fetchAll();

        foreach ($questions as $q) {
            if ($q['question_type'] === 'mcq') {
                $selected = (int) ($_POST['answer'][$q['id']] ?? 0);
                $optStmt = $pdo->prepare('SELECT is_correct FROM ilearning_question_options WHERE id = ? AND question_id = ?');
                $optStmt->execute([$selected, $q['id']]);
                $isCorrect = (bool) $optStmt->fetchColumn();
                $totalQuestions = count($questions);
                $points = $isCorrect ? round(100 / $totalQuestions, 2) : 0;

                $pdo->prepare(
                    'INSERT INTO ilearning_attempt_answers (attempt_id, question_id, selected_option_id, is_correct, points_awarded, graded_by_ai, graded_at)
                     VALUES (?, ?, ?, ?, ?, 0, NOW())'
                )->execute([$attemptId, $q['id'], $selected ?: null, $isCorrect ? 1 : 0, $points]);
            } else {
                $freeText = trim($_POST['answer'][$q['id']] ?? '');
                $totalQuestions = count($questions);

                $graded = $freeText !== ''
                    ? AiGrader::gradeShortAnswer($q['question_text'], (string) $q['model_answer'], $q['grading_rubric'], $freeText)
                    : null;

                if ($graded !== null) {
                    $points = round(($graded['score'] / 100) * (100 / $totalQuestions), 2);
                    $pdo->prepare(
                        'INSERT INTO ilearning_attempt_answers (attempt_id, question_id, free_text_answer, points_awarded, teacher_feedback, graded_by_ai, graded_at)
                         VALUES (?, ?, ?, ?, ?, 1, NOW())'
                    )->execute([$attemptId, $q['id'], $freeText, $points, 'AI: ' . $graded['feedback']]);
                } else {
                    // AI grading failed (or empty answer) -- leave ungraded;
                    // this one question waits for grade_open_answers.php,
                    // everything else in the attempt is unaffected.
                    $pdo->prepare(
                        'INSERT INTO ilearning_attempt_answers (attempt_id, question_id, free_text_answer, points_awarded, graded_by_ai, graded_at)
                         VALUES (?, ?, ?, NULL, 0, NULL)'
                    )->execute([$attemptId, $q['id'], $freeText]);
                }
            }
        }

        $pdo->prepare("UPDATE ilearning_attempts SET status = 'submitted', submitted_at = NOW() WHERE id = ?")->execute([$attemptId]);
        ilearning_try_finalize_attempt($pdo, $attemptId);

        header('Location: take_assessment.php?topic_id=' . $topic_id);
        exit;
    }
}

// Reload attempt after a possible POST above.
$attStmt->execute([$student_id, $topic_id]);
$attempt = $attStmt->fetch();

$questions = [];
$answersByQuestion = [];
if (!$attempt || $attempt['status'] === 'in_progress') {
    $qStmt = $pdo->prepare('SELECT * FROM ilearning_questions WHERE topic_id = ? ORDER BY id');
    $qStmt->execute([$topic_id]);
    $questions = $qStmt->fetchAll();
    foreach ($questions as &$q) {
        if ($q['question_type'] === 'mcq') {
            $optStmt = $pdo->prepare('SELECT * FROM ilearning_question_options WHERE question_id = ? ORDER BY sort_order');
            $optStmt->execute([$q['id']]);
            $q['options'] = $optStmt->fetchAll();
        }
    }
    unset($q);
} else {
    $aStmt = $pdo->prepare(
        'SELECT aa.*, q.question_text, q.question_type FROM ilearning_attempt_answers aa
         JOIN ilearning_questions q ON q.id = aa.question_id WHERE aa.attempt_id = ? ORDER BY aa.id'
    );
    $aStmt->execute([$attempt['id']]);
    $answersByQuestion = $aStmt->fetchAll();
}
?>
<?php
$stu2_stmt = $pdo->prepare('SELECT full_name FROM students WHERE id = ? AND school_id = ?');
$stu2_stmt->execute([$student_id, $school_id]);
$student = $stu2_stmt->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../elections/_election_helpers.php';
$votable_ballot = array_filter(
    election_approved_ballot_for_student($pdo, $school_id, $student_id),
    static fn($position) => !$position['already_voted']
);
$open_positions_to_vote = count($votable_ballot);

$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.student_id = ? AND cv.school_id = ? AND cm.sender_role = 'teacher' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$student_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'ilearning';
require_once __DIR__ . '/../_student_shell.php';
?>
<style>
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:22px;margin-bottom:16px;}
.qnum{font-size:0.7rem;color:var(--muted);text-transform:uppercase;}
.qtext{font-weight:600;margin:6px 0 12px;}
.opt{display:block;padding:10px 12px;background:#111826;border-radius:6px;margin-bottom:8px;cursor:pointer;}
.opt input{margin-right:8px;}
textarea{width:100%;min-height:90px;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:10px;border-radius:6px;font-family:inherit;}
button{cursor:pointer;border:none;border-radius:6px;padding:12px 24px;font-weight:700;font-size:0.9rem;background:var(--cyan);color:#04121a;}
.result-score{font-size:2rem;font-weight:800;color:var(--green);}
.pill{display:inline-block;font-size:0.7rem;padding:2px 10px;border-radius:20px;font-weight:700;margin-left:8px;}
.pill-correct{background:rgba(16,185,129,0.15);color:var(--green);}
.pill-wrong{background:rgba(239,68,68,0.12);color:var(--danger);}
.pill-pending{background:rgba(245,158,11,0.15);color:var(--amber);}
.feedback{background:#111826;border-radius:6px;padding:10px;margin-top:8px;font-size:0.85rem;color:var(--muted);}
<?php include __DIR__ . '/../_rich_toolbar_style.php'; ?>
</style>
<div class="page-title-row" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
    <h1 style="margin:0;font-size:1.1rem;"><?= htmlspecialchars($topic['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <a href="view_topic.php?id=<?= $topic_id ?>" class="btn-link" style="color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;">← Back to Topic</a>
</div>

    <?php if (!$attempt || $attempt['status'] === 'in_progress'): ?>
        <?php if (empty($questions)): ?>
            <div class="card">No questions have been added to this assessment yet.</div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="topic_id" value="<?= $topic_id ?>">
            <?php foreach ($questions as $i => $q): ?>
            <div class="card">
                <div class="qnum">Question <?= $i + 1 ?> of <?= count($questions) ?> — <?= $q['question_type'] === 'mcq' ? 'Multiple Choice' : 'Short Answer' ?></div>
                <div class="qtext"><?= scholar_render_rich_text($q['question_text']) ?></div>
                <?php if ($q['question_type'] === 'mcq'): ?>
                    <?php foreach ($q['options'] as $opt): ?>
                    <label class="opt">
                        <input type="radio" name="answer[<?= $q['id'] ?>]" value="<?= (int) $opt['id'] ?>" required>
                        <?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <textarea name="answer[<?= $q['id'] ?>]" class="rt-editable" placeholder="Type your answer..." required></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button type="submit" name="submit_attempt">Submit Assessment</button>
        </form>
        <?php endif; ?>
    <?php else: ?>
        <div class="card">
            <?php if ($attempt['status'] === 'graded'): ?>
                <div class="result-score"><?= number_format((float) $attempt['score_percentage'], 1) ?>%</div>
            <?php else: ?>
                <p style="color:var(--amber);">Submitted — one or more answers are still awaiting your teacher's review.</p>
            <?php endif; ?>
        </div>
        <?php foreach ($answersByQuestion as $a): ?>
        <div class="card">
            <div class="qtext"><?= scholar_render_rich_text($a['question_text']) ?>
                <?php if ($a['points_awarded'] === null): ?>
                    <span class="pill pill-pending">Pending</span>
                <?php elseif ($a['question_type'] === 'mcq'): ?>
                    <span class="pill <?= $a['is_correct'] ? 'pill-correct' : 'pill-wrong' ?>"><?= $a['is_correct'] ? 'Correct' : 'Incorrect' ?></span>
                <?php else: ?>
                    <span class="pill pill-correct"><?= number_format((float) $a['points_awarded'], 1) ?> pts</span>
                <?php endif; ?>
            </div>
            <?php if ($a['free_text_answer']): ?><div style="color:var(--muted);font-size:0.85rem;">Your answer: <?= htmlspecialchars($a['free_text_answer'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($a['teacher_feedback']): ?><div class="feedback"><?= htmlspecialchars($a['teacher_feedback'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
        </div>
    </div>
</div>
<script src="../assets/js/rich-toolbar.js"></script>
</body>
</html>

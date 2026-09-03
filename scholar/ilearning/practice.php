<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_ilearning_helpers.php';
require_once __DIR__ . '/AiGrader.php';

$school_id = current_school_id();
$student_id = current_student_id();

$stuStmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
$stuStmt->execute([$student_id, $school_id]);
$class_id = (int) ($stuStmt->fetchColumn() ?: 0);

$subjStmt = $pdo->prepare('SELECT DISTINCT s.id, s.subject_name FROM ilearning_questions q JOIN subjects s ON s.id = q.subject_id WHERE q.school_id = ? AND q.class_id = ? AND q.pool_type = "standing_practice" ORDER BY s.subject_name');
$subjStmt->execute([$school_id, $class_id]);
$subjects = $subjStmt->fetchAll();

$subject_id = (int) ($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_practice']) && $subject_id > 0) {
    $qStmt = $pdo->prepare('SELECT * FROM ilearning_questions WHERE school_id=? AND class_id=? AND subject_id=? AND pool_type="standing_practice" ORDER BY id');
    $qStmt->execute([$school_id, $class_id, $subject_id]);
    $questions = $qStmt->fetchAll();

    if (!empty($questions)) {
        $pdo->prepare("INSERT INTO ilearning_attempts (school_id, student_id, topic_id, pool_type, status) VALUES (?, ?, NULL, 'standing_practice', 'in_progress')")
            ->execute([$school_id, $student_id]);
        $attemptId = (int) $pdo->lastInsertId();
        $result = [];

        foreach ($questions as $q) {
            $totalQuestions = count($questions);
            if ($q['question_type'] === 'mcq') {
                $selected = (int) ($_POST['answer'][$q['id']] ?? 0);
                $optStmt = $pdo->prepare('SELECT is_correct FROM ilearning_question_options WHERE id = ? AND question_id = ?');
                $optStmt->execute([$selected, $q['id']]);
                $isCorrect = (bool) $optStmt->fetchColumn();
                $points = $isCorrect ? round(100 / $totalQuestions, 2) : 0;
                $pdo->prepare('INSERT INTO ilearning_attempt_answers (attempt_id, question_id, selected_option_id, is_correct, points_awarded, graded_by_ai, graded_at) VALUES (?, ?, ?, ?, ?, 0, NOW())')
                    ->execute([$attemptId, $q['id'], $selected ?: null, $isCorrect ? 1 : 0, $points]);
                $result[] = ['question_text' => $q['question_text'], 'type' => 'mcq', 'correct' => $isCorrect, 'feedback' => null];
            } else {
                $freeText = trim($_POST['answer'][$q['id']] ?? '');
                $graded = $freeText !== '' ? AiGrader::gradeShortAnswer($q['question_text'], (string) $q['model_answer'], $q['grading_rubric'], $freeText) : null;
                if ($graded !== null) {
                    $points = round(($graded['score'] / 100) * (100 / $totalQuestions), 2);
                    $pdo->prepare('INSERT INTO ilearning_attempt_answers (attempt_id, question_id, free_text_answer, points_awarded, teacher_feedback, graded_by_ai, graded_at) VALUES (?, ?, ?, ?, ?, 1, NOW())')
                        ->execute([$attemptId, $q['id'], $freeText, $points, $graded['feedback']]);
                    $result[] = ['question_text' => $q['question_text'], 'type' => 'short_answer', 'score' => $graded['score'], 'feedback' => $graded['feedback']];
                } else {
                    $pdo->prepare('INSERT INTO ilearning_attempt_answers (attempt_id, question_id, free_text_answer, points_awarded, graded_by_ai, graded_at) VALUES (?, ?, ?, NULL, 0, NULL)')
                        ->execute([$attemptId, $q['id'], $freeText]);
                    $result[] = ['question_text' => $q['question_text'], 'type' => 'short_answer', 'score' => null, 'feedback' => 'Could not auto-grade this one right now — try again later.'];
                }
            }
        }

        $pdo->prepare("UPDATE ilearning_attempts SET status='submitted', submitted_at = NOW() WHERE id = ?")->execute([$attemptId]);
        ilearning_try_finalize_attempt($pdo, $attemptId); // never touches student_marks -- topic_id is NULL
    }
}

$questions = [];
if ($subject_id > 0 && $result === null) {
    $qStmt = $pdo->prepare('SELECT * FROM ilearning_questions WHERE school_id=? AND class_id=? AND subject_id=? AND pool_type="standing_practice" ORDER BY id');
    $qStmt->execute([$school_id, $class_id, $subject_id]);
    $questions = $qStmt->fetchAll();
    foreach ($questions as &$q) {
        if ($q['question_type'] === 'mcq') {
            $optStmt = $pdo->prepare('SELECT * FROM ilearning_question_options WHERE question_id = ? ORDER BY sort_order');
            $optStmt->execute([$q['id']]);
            $q['options'] = $optStmt->fetchAll();
        }
    }
    unset($q);
}

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
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:16px;}
select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:10px;border-radius:6px;}
.opt{display:block;padding:10px 12px;background:#111826;border-radius:6px;margin-bottom:8px;cursor:pointer;}
textarea{width:100%;min-height:80px;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:10px;border-radius:6px;font-family:inherit;}
button{cursor:pointer;border:none;border-radius:6px;padding:11px 22px;font-weight:700;background:var(--cyan);color:#04121a;}
.pill{display:inline-block;font-size:0.7rem;padding:2px 10px;border-radius:20px;font-weight:700;margin-left:8px;}
.pill-correct{background:rgba(16,185,129,0.15);color:var(--green);}
.pill-wrong{background:rgba(239,68,68,0.12);color:var(--danger);}
.feedback{background:#111826;border-radius:6px;padding:10px;margin-top:8px;font-size:0.85rem;color:var(--muted);}
<?php include __DIR__ . '/../_rich_toolbar_style.php'; ?>
</style>
<div class="page-title-row">
    <h1>Practice Questions</h1>
</div>

    <div class="card">
        <form method="GET">
            <label style="font-size:0.75rem;color:var(--muted);text-transform:uppercase;">Subject</label>
            <select name="subject_id" onchange="this.form.submit();">
                <option value="">-- Choose a subject --</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $subject_id === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['subject_name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($result !== null): ?>
        <?php foreach ($result as $r): ?>
        <div class="card">
            <?= scholar_render_rich_text($r['question_text']) ?>
            <?php if ($r['type'] === 'mcq'): ?>
                <span class="pill <?= $r['correct'] ? 'pill-correct' : 'pill-wrong' ?>"><?= $r['correct'] ? 'Correct' : 'Incorrect' ?></span>
            <?php elseif ($r['score'] !== null): ?>
                <span class="pill pill-correct"><?= (int) $r['score'] ?>/100</span>
                <div class="feedback"><?= htmlspecialchars($r['feedback'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php else: ?>
                <div class="feedback"><?= htmlspecialchars($r['feedback'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <a href="practice.php?subject_id=<?= $subject_id ?>" class="btn-link">Try more questions →</a>
    <?php elseif ($subject_id > 0): ?>
        <?php if (empty($questions)): ?>
            <div class="card">No practice questions available for this subject yet.</div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
            <?php foreach ($questions as $q): ?>
            <div class="card">
                <div style="font-weight:600;margin-bottom:10px;"><?= scholar_render_rich_text($q['question_text']) ?></div>
                <?php if ($q['question_type'] === 'mcq'): ?>
                    <?php foreach ($q['options'] as $opt): ?>
                    <label class="opt"><input type="radio" name="answer[<?= $q['id'] ?>]" value="<?= (int) $opt['id'] ?>" required> <?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?></label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <textarea name="answer[<?= $q['id'] ?>]" class="rt-editable" placeholder="Type your answer..." required></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button type="submit" name="submit_practice">Check My Answers</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
        </div>
    </div>
</div>
<script src="../assets/js/rich-toolbar.js"></script>
</body>
</html>

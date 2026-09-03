<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/_ilearning_helpers.php';

$school_id = current_school_id();
$staff_id = (int) ($_SESSION['staff_id'] ?? 0);
$error = '';
$success = '';

$topic_id = isset($_GET['topic_id']) ? (int) $_GET['topic_id'] : (isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0);
$class_id = isset($_GET['class_id']) ? (int) $_GET['class_id'] : (isset($_POST['class_id']) ? (int) $_POST['class_id'] : 0);
$subject_id = isset($_GET['subject_id']) ? (int) $_GET['subject_id'] : (isset($_POST['subject_id']) ? (int) $_POST['subject_id'] : 0);
$pool_type = $topic_id > 0 ? 'topic_assessment' : 'standing_practice';

$topic = null;
if ($topic_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM ilearning_topics WHERE id = ? AND school_id = ? AND teacher_id = ?');
    $stmt->execute([$topic_id, $school_id, $staff_id]);
    $topic = $stmt->fetch();
    if (!$topic) {
        http_response_code(403);
        die('Topic not found, or you are not its author.');
    }
    $class_id = (int) $topic['class_id'];
    $subject_id = (int) $topic['subject_id'];
}

$assigned_stmt = $pdo->prepare("
    SELECT ta.class_id, c.class_name, ta.subject_id, s.subject_name
    FROM teacher_assignments ta
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    WHERE ta.school_id = ? AND ta.teacher_id = ?
    ORDER BY c.class_name, s.subject_name
");
$assigned_stmt->execute([$school_id, $staff_id]);
$my_assignments = $assigned_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    if (!ilearning_teacher_authorized($pdo, $school_id, $staff_id, $class_id, $subject_id)) {
        $error = 'You are not assigned to teach that class/subject.';
    } else {
        $question_type = in_array($_POST['question_type'] ?? '', ['mcq', 'short_answer'], true) ? $_POST['question_type'] : 'mcq';
        $question_text = trim($_POST['question_text'] ?? '');

        if ($question_text === '') {
            $error = 'Enter the question text.';
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO ilearning_questions (school_id, class_id, subject_id, topic_id, pool_type, question_type, question_text, model_answer, grading_rubric, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if ($question_type === 'mcq') {
                $options = array_values(array_filter(array_map('trim', $_POST['option'] ?? []), fn($o) => $o !== ''));
                $correctIndex = (int) ($_POST['correct_option'] ?? -1);
                if (count($options) < 2) {
                    $error = 'Enter at least 2 answer options.';
                } elseif (!isset($options[$correctIndex])) {
                    $error = 'Choose which option is correct.';
                } else {
                    $ins->execute([$school_id, $class_id, $subject_id, $topic_id ?: null, $pool_type, 'mcq', $question_text, null, null, $staff_id]);
                    $questionId = (int) $pdo->lastInsertId();
                    $optStmt = $pdo->prepare('INSERT INTO ilearning_question_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)');
                    foreach ($options as $i => $opt) {
                        $optStmt->execute([$questionId, $opt, $i === $correctIndex ? 1 : 0, $i]);
                    }
                    $success = 'Question added.';
                }
            } else {
                $model_answer = trim($_POST['model_answer'] ?? '');
                $rubric = trim($_POST['grading_rubric'] ?? '');
                if ($model_answer === '') {
                    $error = 'Provide a model answer so the AI grader has something to compare against.';
                } else {
                    $ins->execute([$school_id, $class_id, $subject_id, $topic_id ?: null, $pool_type, 'short_answer', $question_text, $model_answer, $rubric ?: null, $staff_id]);
                    $success = 'Question added.';
                }
            }
        }
    }
}

if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $pdo->prepare('DELETE FROM ilearning_questions WHERE id = ? AND created_by = ? AND school_id = ?')
        ->execute([(int) $_GET['delete'], $staff_id, $school_id]);
    header('Location: questions.php?' . ($topic_id ? 'topic_id=' . $topic_id : 'class_id=' . $class_id . '&subject_id=' . $subject_id));
    exit;
}

$questions = [];
if ($topic_id > 0 || ($class_id > 0 && $subject_id > 0)) {
    if ($topic_id > 0) {
        $q_stmt = $pdo->prepare('SELECT * FROM ilearning_questions WHERE topic_id = ? ORDER BY id');
        $q_stmt->execute([$topic_id]);
    } else {
        $q_stmt = $pdo->prepare("SELECT * FROM ilearning_questions WHERE school_id=? AND class_id=? AND subject_id=? AND pool_type='standing_practice' AND topic_id IS NULL ORDER BY id");
        $q_stmt->execute([$school_id, $class_id, $subject_id]);
    }
    $questions = $q_stmt->fetchAll();
    foreach ($questions as &$q) {
        if ($q['question_type'] === 'mcq') {
            $opt_stmt = $pdo->prepare('SELECT * FROM ilearning_question_options WHERE question_id = ? ORDER BY sort_order');
            $opt_stmt->execute([$q['id']]);
            $q['options'] = $opt_stmt->fetchAll();
        }
    }
    unset($q);
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
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:24px;margin-bottom:20px;}
.field{margin-bottom:14px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input,select,textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px;border-radius:6px;font-size:0.88rem;font-family:inherit;}
textarea{min-height:70px;resize:vertical;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.qitem{border-bottom:1px solid var(--border);padding:14px 0;}
.qitem:last-child{border-bottom:none;}
.qtype{font-size:0.7rem;text-transform:uppercase;color:var(--cyan);font-weight:700;}
.opt{padding:4px 0 4px 14px;font-size:0.85rem;color:var(--muted);}
.opt.correct{color:var(--green);font-weight:700;}
a.del{color:var(--danger);text-decoration:none;font-size:0.75rem;float:right;}
.optrow{display:flex;gap:8px;align-items:center;margin-bottom:8px;}
<?php include __DIR__ . '/../_rich_toolbar_style.php'; ?>
</style>
<div class="page-title-row">
    <h1>Questions <?= $topic ? '— ' . htmlspecialchars($topic['title'], ENT_QUOTES, 'UTF-8') : '(Standing Practice Pool)' ?></h1>
</div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if (!$topic_id): ?>
    <div class="card">
        <form method="GET">
            <div class="field">
                <label>Standing Practice Pool — Class &amp; Subject</label>
                <select name="class_subject" onchange="var p=this.value.split('|');document.getElementById('gc').value=p[0];document.getElementById('gs').value=p[1];this.form.submit();">
                    <option value="">-- Choose --</option>
                    <?php foreach ($my_assignments as $a): ?>
                        <option value="<?= (int) $a['class_id'] ?>|<?= (int) $a['subject_id'] ?>" <?= ($class_id == $a['class_id'] && $subject_id == $a['subject_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['class_name'] . ' — ' . $a['subject_name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" id="gc" name="class_id" value="<?= (int) $class_id ?>">
                <input type="hidden" id="gs" name="subject_id" value="<?= (int) $subject_id ?>">
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($topic_id > 0 || ($class_id > 0 && $subject_id > 0)): ?>
    <div class="card">
        <form method="POST">
            <input type="hidden" name="topic_id" value="<?= (int) $topic_id ?>">
            <input type="hidden" name="class_id" value="<?= (int) $class_id ?>">
            <input type="hidden" name="subject_id" value="<?= (int) $subject_id ?>">

            <div class="field">
                <label>Question Type</label>
                <select name="question_type" onchange="document.getElementById('mcqFields').style.display=this.value==='mcq'?'block':'none';document.getElementById('saFields').style.display=this.value==='short_answer'?'block':'none';">
                    <option value="mcq">Multiple Choice (instant auto-mark)</option>
                    <option value="short_answer">Short Answer (AI-graded)</option>
                </select>
            </div>

            <div class="field">
                <label>Question Text</label>
                <textarea name="question_text" class="rt-editable" required></textarea>
            </div>

            <div id="mcqFields">
                <label>Answer Options (mark the correct one)</label>
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="optrow">
                    <input type="radio" name="correct_option" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>>
                    <input type="text" name="option[]" placeholder="Option <?= $i + 1 ?>">
                </div>
                <?php endfor; ?>
            </div>

            <div id="saFields" style="display:none;">
                <div class="field">
                    <label>Model Answer (what the AI grader compares against)</label>
                    <textarea name="model_answer" class="rt-editable"></textarea>
                </div>
                <div class="field">
                    <label>Grading Rubric / Key Points (optional)</label>
                    <textarea name="grading_rubric"></textarea>
                </div>
            </div>

            <button type="submit" name="add_question">Add Question</button>
        </form>
    </div>

    <div class="card">
        <?php if (empty($questions)): ?>
            <p style="color:var(--muted);">No questions yet.</p>
        <?php endif; ?>
        <?php foreach ($questions as $q): ?>
        <div class="qitem">
            <a class="del" href="?<?= $topic_id ? 'topic_id=' . $topic_id : 'class_id=' . $class_id . '&subject_id=' . $subject_id ?>&delete=<?= (int) $q['id'] ?>" onclick="return confirm('Delete this question?');">Delete</a>
            <span class="qtype"><?= $q['question_type'] === 'mcq' ? 'MCQ' : 'Short Answer' ?></span>
            <p style="margin:6px 0;"><?= scholar_render_rich_text($q['question_text']) ?></p>
            <?php if ($q['question_type'] === 'mcq'): ?>
                <?php foreach ($q['options'] as $opt): ?>
                    <div class="opt <?= $opt['is_correct'] ? 'correct' : '' ?>"><?= $opt['is_correct'] ? '✓ ' : '— ' ?><?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="opt">Model answer: <?= scholar_render_rich_text($q['model_answer']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
        </div>
    </div>
</div>
<script src="../assets/js/rich-toolbar.js"></script>
</body>
</html>

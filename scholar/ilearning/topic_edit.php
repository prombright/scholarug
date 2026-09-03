<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/_ilearning_helpers.php';
require_once __DIR__ . '/../bulksms_client.php';

$school_id = current_school_id();
$staff_id = (int) ($_SESSION['staff_id'] ?? 0);
$topic_id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0);

$error = '';
$success = '';

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

$assessments_stmt = $pdo->prepare("SELECT id, title, term, year FROM assessments WHERE school_id = ? AND status = 'Open' ORDER BY id DESC");
$assessments_stmt->execute([$school_id]);
$open_assessments = $assessments_stmt->fetchAll();

$topic = [
    'id' => 0, 'class_id' => '', 'subject_id' => '', 'title' => '', 'body' => '',
    'term' => '', 'year' => date('Y'), 'target_days_to_complete' => '', 'assessment_id' => '', 'status' => 'Draft',
    'content_type' => 'written', 'primary_pdf_attachment_id' => null,
];

if ($topic_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM ilearning_topics WHERE id = ? AND school_id = ? AND teacher_id = ?');
    $stmt->execute([$topic_id, $school_id, $staff_id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        http_response_code(403);
        die('Topic not found, or you are not its author.');
    }
    $topic = $existing;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_topic'])) {
    $class_id = (int) ($_POST['class_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $term = trim($_POST['term'] ?? '');
    $year = (int) ($_POST['year'] ?? date('Y'));
    $target_days = trim($_POST['target_days_to_complete'] ?? '');
    $assessment_id = $_POST['assessment_id'] !== '' ? (int) $_POST['assessment_id'] : null;
    $status = in_array($_POST['status'] ?? '', ['Draft', 'Published', 'Archived'], true) ? $_POST['status'] : 'Draft';
    $content_type = in_array($_POST['content_type'] ?? '', ['written', 'pdf_notes', 'pdf_activity'], true) ? $_POST['content_type'] : 'written';
    $isPdfType = $content_type !== 'written';
    $hasNewPdf = !empty($_FILES['pdf_file']['name']);

    if (!ilearning_teacher_authorized($pdo, $school_id, $staff_id, $class_id, $subject_id)) {
        $error = 'You are not assigned to teach that class/subject.';
    } elseif ($title === '' || $term === '') {
        $error = 'Title and term are required.';
    } elseif (!$isPdfType && $body === '') {
        $error = 'Notes content is required for a written topic.';
    } elseif ($isPdfType && $topic_id === 0 && !$hasNewPdf) {
        $error = 'Upload a PDF file for a PDF Notes or PDF Activity topic.';
    } else {
        $wasPublished = $topic_id > 0 && $topic['status'] === 'Published';

        if ($topic_id > 0) {
            $upd = $pdo->prepare(
                'UPDATE ilearning_topics SET class_id=?, subject_id=?, title=?, body=?, term=?, year=?,
                 target_days_to_complete=?, assessment_id=?, status=?, content_type=? WHERE id=? AND school_id=? AND teacher_id=?'
            );
            $upd->execute([
                $class_id, $subject_id, $title, $body, $term, $year,
                $target_days !== '' ? (int) $target_days : null, $assessment_id, $status, $content_type,
                $topic_id, $school_id, $staff_id,
            ]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO ilearning_topics (school_id, class_id, subject_id, teacher_id, title, body, term, year, target_days_to_complete, assessment_id, status, content_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $school_id, $class_id, $subject_id, $staff_id, $title, $body, $term, $year,
                $target_days !== '' ? (int) $target_days : null, $assessment_id, $status, $content_type,
            ]);
            $topic_id = (int) $pdo->lastInsertId();
        }

        if ($isPdfType && $hasNewPdf) {
            $saved = ilearning_save_pdf_attachment($_FILES['pdf_file'], $topic_id);
            if ($saved === null) {
                $error = 'That file was not a valid PDF -- it was not saved. The rest of the topic was.';
            } else {
                $insAtt = $pdo->prepare(
                    'INSERT INTO ilearning_attachments (topic_id, file_path, original_name, file_ext, storage) VALUES (?, ?, ?, ?, ?)'
                );
                $insAtt->execute([$topic_id, $saved['path'], $saved['original_name'], $saved['ext'], 'private']);
                $attId = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE ilearning_topics SET primary_pdf_attachment_id = ? WHERE id = ?')->execute([$attId, $topic_id]);
            }
        }

        if (!empty($_FILES['attachment']['name'])) {
            $saved = ilearning_save_attachment($_FILES['attachment'], $topic_id);
            if ($saved !== null) {
                $pdo->prepare('INSERT INTO ilearning_attachments (topic_id, file_path, original_name, file_ext) VALUES (?, ?, ?, ?)')
                    ->execute([$topic_id, $saved['path'], $saved['original_name'], $saved['ext']]);
            }
        }

        $success = 'Topic saved.';

        // Notify the class's parents the first time a topic goes Published
        // -- same BulkSmsClient channel school_admin/message_parents.php
        // already uses, billed against Scholar's own Bulk SMS wallet.
        if ($status === 'Published' && !$wasPublished) {
            $phoneStmt = $pdo->prepare("
                SELECT DISTINCT u.phone_number FROM users u
                JOIN parent_students ps ON ps.user_id = u.id
                JOIN students s ON s.id = ps.student_id
                WHERE u.school_id = ? AND u.role = 'parent' AND s.class_id = ?
                      AND u.phone_number IS NOT NULL AND u.phone_number <> ''
            ");
            $phoneStmt->execute([$school_id, $class_id]);
            $phones = array_column($phoneStmt->fetchAll(), 'phone_number');
            if (!empty($phones)) {
                try {
                    BulkSmsClient::send("New Scholar iLearning note posted: \"{$title}\". Ask your child to check their portal.", $phones);
                    $success .= ' Parents notified by SMS.';
                } catch (Throwable $e) {
                    $success .= ' (Parent SMS notification failed: ' . $e->getMessage() . ')';
                }
            }
        }

        // Refresh local state so the form reflects what was just saved.
        $stmt = $pdo->prepare('SELECT * FROM ilearning_topics WHERE id = ?');
        $stmt->execute([$topic_id]);
        $topic = $stmt->fetch();
    }
}

$attachments = [];
$primary_pdf = null;
if ($topic_id > 0) {
    $a_stmt = $pdo->prepare('SELECT * FROM ilearning_attachments WHERE topic_id = ? ORDER BY uploaded_at DESC');
    $a_stmt->execute([$topic_id]);
    $attachments = $a_stmt->fetchAll();

    if (!empty($topic['primary_pdf_attachment_id'])) {
        $p_stmt = $pdo->prepare('SELECT * FROM ilearning_attachments WHERE id = ?');
        $p_stmt->execute([(int) $topic['primary_pdf_attachment_id']]);
        $primary_pdf = $p_stmt->fetch() ?: null;
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
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:24px;}
.field{margin-bottom:16px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input,select,textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:10px;border-radius:6px;font-size:0.9rem;font-family:inherit;}
textarea{min-height:160px;resize:vertical;}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
button{cursor:pointer;border:none;border-radius:6px;padding:11px 20px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.hint{color:var(--muted);font-size:0.75rem;margin-top:4px;}
ul.attachments{list-style:none;padding:0;margin:8px 0 0;}
ul.attachments li{padding:6px 0;border-bottom:1px solid var(--border);font-size:0.85rem;}
ul.attachments a{color:var(--cyan);}
<?php include __DIR__ . '/../_rich_toolbar_style.php'; ?>
</style>
<div class="page-title-row">
    <h1><?= $topic_id ? 'Edit Topic' : 'New Topic' ?></h1>
</div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="topic_id" value="<?= (int) $topic_id ?>">

            <div class="field">
                <label>Class &amp; Subject</label>
                <select name="class_subject" required onchange="var p=this.value.split('|');document.getElementById('class_id').value=p[0];document.getElementById('subject_id').value=p[1];">
                    <option value="">-- Choose --</option>
                    <?php foreach ($my_assignments as $a): ?>
                        <option value="<?= (int) $a['class_id'] ?>|<?= (int) $a['subject_id'] ?>" <?= ($topic['class_id'] == $a['class_id'] && $topic['subject_id'] == $a['subject_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['class_name'] . ' — ' . $a['subject_name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" id="class_id" name="class_id" value="<?= htmlspecialchars((string) $topic['class_id'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" id="subject_id" name="subject_id" value="<?= htmlspecialchars((string) $topic['subject_id'], ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="field">
                <label>Title</label>
                <input type="text" name="title" value="<?= htmlspecialchars($topic['title'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="field">
                <label>Content Type</label>
                <select name="content_type" id="contentType" onchange="scholarToggleContentType()">
                    <option value="written" <?= $topic['content_type'] === 'written' ? 'selected' : '' ?>>Written Notes (typed text)</option>
                    <option value="pdf_notes" <?= $topic['content_type'] === 'pdf_notes' ? 'selected' : '' ?>>PDF Notes (students can view &amp; download, no response)</option>
                    <option value="pdf_activity" <?= $topic['content_type'] === 'pdf_activity' ? 'selected' : '' ?>>PDF Activity (view only, students type an answer)</option>
                </select>
                <div class="hint">A PDF Activity cannot be downloaded by students -- they read it inline and type their answer alongside it.</div>
            </div>

            <div class="field" id="bodyField">
                <label id="bodyLabel">Notes Content</label>
                <textarea name="body" id="bodyTextarea" class="rt-editable"><?= htmlspecialchars($topic['body'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="field" id="pdfField" style="display:none;">
                <label>Upload PDF</label>
                <input type="file" name="pdf_file" accept=".pdf">
                <?php if ($primary_pdf): ?>
                    <div class="hint">Currently: <?= htmlspecialchars($primary_pdf['original_name'], ENT_QUOTES, 'UTF-8') ?> — choose a new file above to replace it.</div>
                <?php endif; ?>
            </div>

            <div class="row2">
                <div class="field">
                    <label>Term</label>
                    <input type="text" name="term" value="<?= htmlspecialchars($topic['term'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Term 1" required>
                </div>
                <div class="field">
                    <label>Year</label>
                    <input type="number" name="year" value="<?= htmlspecialchars((string) $topic['year'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            </div>

            <div class="row2">
                <div class="field">
                    <label>Target Days to Complete (optional)</label>
                    <input type="number" name="target_days_to_complete" value="<?= htmlspecialchars((string) $topic['target_days_to_complete'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 7">
                </div>
                <div class="field">
                    <label>Graded Assessment (optional)</label>
                    <select name="assessment_id">
                        <option value="">-- Practice only, no grade --</option>
                        <?php foreach ($open_assessments as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" <?= (string) $topic['assessment_id'] === (string) $a['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['title'] . ' (' . $a['term'] . ' ' . $a['year'] . ')', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">Linking an assessment lets this topic's questions feed into the report card once graded.</div>
                </div>
            </div>

            <div class="field">
                <label>Add Attachment (PDF, Word, PowerPoint, or image)</label>
                <input type="file" name="attachment" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png">
                <?php if ($attachments): ?>
                <ul class="attachments">
                    <?php foreach ($attachments as $att): ?>
                        <li><a href="../<?= htmlspecialchars($att['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank"><?= htmlspecialchars($att['original_name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="Draft" <?= $topic['status'] === 'Draft' ? 'selected' : '' ?>>Draft (not visible to students)</option>
                    <option value="Published" <?= $topic['status'] === 'Published' ? 'selected' : '' ?>>Published (visible, parents notified)</option>
                    <option value="Archived" <?= $topic['status'] === 'Archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>

            <button type="submit" name="save_topic">Save Topic</button>
        </form>
    </div>
        </div>
    </div>
</div>
<script>
function scholarToggleContentType() {
    var type = document.getElementById('contentType').value;
    var isPdf = type !== 'written';
    document.getElementById('pdfField').style.display = isPdf ? 'block' : 'none';
    document.getElementById('bodyLabel').textContent = isPdf ? 'Instructions (optional)' : 'Notes Content';
    document.getElementById('bodyTextarea').required = !isPdf;
}
scholarToggleContentType();
</script>
<script src="../assets/js/rich-toolbar.js"></script>
</body>
</html>

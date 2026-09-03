<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/_library_helpers.php';

$school_id = current_school_id();
$staff_id = current_staff_id();

$assigned_stmt = $pdo->prepare("
    SELECT DISTINCT ta.class_id, c.class_name, ta.subject_id, s.subject_name
    FROM teacher_assignments ta
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    WHERE ta.school_id = ? AND ta.teacher_id = ?
    ORDER BY c.class_name, s.subject_name
");
$assigned_stmt->execute([$school_id, $staff_id]);
$my_assignments = $assigned_stmt->fetchAll();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = $_POST['category'] ?? '';
        $class_id = (int) ($_POST['class_id'] ?? 0);
        $subject_id = (int) ($_POST['subject_id'] ?? 0);
        $term = trim((string) ($_POST['term'] ?? ''));
        $year = trim((string) ($_POST['year'] ?? ''));

        $assigned_pair = false;
        foreach ($my_assignments as $a) {
            if ((int) $a['class_id'] === $class_id && (int) $a['subject_id'] === $subject_id) {
                $assigned_pair = true;
                break;
            }
        }

        if ($title === '' || !in_array($category, ['notes', 'past_paper'], true) || !$assigned_pair) {
            $error = 'Please fill in a title and pick a class/subject you are assigned to.';
        } else {
            $saved = library_save_pdf($_FILES['pdf'] ?? []);
            if ($saved === null) {
                $error = 'Please upload a valid PDF file.';
            } else {
                $pdo->prepare("
                    INSERT INTO library_documents (school_id, class_id, subject_id, teacher_id, category, title, term, year, file_path, original_name)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $school_id, $class_id, $subject_id, $staff_id, $category, $title,
                    $term !== '' ? $term : null,
                    $year !== '' ? (int) $year : null,
                    $saved['path'], $saved['original_name'],
                ]);
                $success = 'Uploaded.';
            }
        }
    } elseif ($action === 'delete') {
        $doc_id = (int) ($_POST['doc_id'] ?? 0);
        if (library_delete_document($pdo, $doc_id, $school_id, $staff_id)) {
            $success = 'Deleted.';
        }
    } elseif ($action === 'toggle_status') {
        $doc_id = (int) ($_POST['doc_id'] ?? 0);
        library_toggle_status($pdo, $doc_id, $school_id, $staff_id);
    }
}

$docs_stmt = $pdo->prepare("
    SELECT d.*, c.class_name, s.subject_name
    FROM library_documents d
    JOIN classes c ON c.id = d.class_id
    JOIN subjects s ON s.id = d.subject_id
    WHERE d.school_id = ? AND d.teacher_id = ?
    ORDER BY d.created_at DESC
");
$docs_stmt->execute([$school_id, $staff_id]);
$docs = $docs_stmt->fetchAll();

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

$ACTIVE_NAV = 'library';
require_once __DIR__ . '/../_teacher_shell.php';
?>
<style>
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-error{background:rgba(239,68,68,0.12);color:var(--danger);}
.alert-ok{background:rgba(16,185,129,0.12);color:var(--green);}
.upload-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input,select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:12px 14px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:20px;text-align:center;}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;text-transform:uppercase;}
.badge-draft{background:rgba(100,116,139,0.2);color:var(--muted);}
.badge-published{background:rgba(16,185,129,0.15);color:var(--green);}
.cat-notes{color:var(--cyan);}
.cat-past_paper{color:var(--amber);}
a.act{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:600;margin-right:10px;}
form.inline{display:inline;}
button.link-btn{background:none;color:var(--danger);padding:0;font-weight:600;font-size:0.8rem;cursor:pointer;}
button.status-btn{background:none;color:var(--cyan);padding:0;font-weight:600;font-size:0.8rem;cursor:pointer;}
</style>
<div class="page-title-row">
    <h1>Library</h1>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-ok"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<?php if (empty($my_assignments)): ?>
    <div class="section"><div class="empty">You have no class/subject assignments yet — ask your school admin to assign you via Teacher Assignments.</div></div>
<?php else: ?>
    <div class="section">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <div class="upload-grid">
                <div>
                    <label>Title</label>
                    <input type="text" name="title" required maxlength="255" placeholder="e.g. Chapter 4 Notes">
                </div>
                <div>
                    <label>Type</label>
                    <select name="category" required>
                        <option value="notes">Notes</option>
                        <option value="past_paper">Past Paper</option>
                    </select>
                </div>
                <div>
                    <label>Class / Subject</label>
                    <select name="class_subject" required onchange="var v=this.value.split('|');document.getElementById('classIdField').value=v[0];document.getElementById('subjectIdField').value=v[1];">
                        <?php foreach ($my_assignments as $a): ?>
                            <option value="<?= (int) $a['class_id'] ?>|<?= (int) $a['subject_id'] ?>"><?= htmlspecialchars($a['class_name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($a['subject_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="class_id" id="classIdField" value="<?= (int) $my_assignments[0]['class_id'] ?>">
                    <input type="hidden" name="subject_id" id="subjectIdField" value="<?= (int) $my_assignments[0]['subject_id'] ?>">
                </div>
                <div>
                    <label>Term (optional)</label>
                    <input type="text" name="term" maxlength="20" placeholder="e.g. Term 2">
                </div>
                <div>
                    <label>Year (optional)</label>
                    <input type="number" name="year" min="2000" max="2100" placeholder="<?= date('Y') ?>">
                </div>
                <div>
                    <label>PDF File</label>
                    <input type="file" name="pdf" accept="application/pdf" required>
                </div>
                <div>
                    <button type="submit">Upload</button>
                </div>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="section" style="padding:0;">
    <table>
        <thead>
            <tr><th>Title</th><th>Type</th><th>Class</th><th>Subject</th><th>Term</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            <?php if (empty($docs)): ?>
            <tr><td colspan="7" class="empty">No documents yet — upload your first one above.</td></tr>
            <?php endif; ?>
            <?php foreach ($docs as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="cat-<?= $d['category'] ?>"><?= $d['category'] === 'notes' ? 'Notes' : 'Past Paper' ?></td>
                <td><?= htmlspecialchars($d['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($d['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(trim(($d['term'] ?? '') . ' ' . ($d['year'] ?? '')), ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                <td><span class="badge badge-<?= strtolower($d['status']) ?>"><?= htmlspecialchars($d['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td>
                    <a class="act" href="view.php?id=<?= (int) $d['id'] ?>" target="_blank">Preview</a>
                    <form class="inline" method="POST">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
                        <button type="submit" class="status-btn"><?= $d['status'] === 'Published' ? 'Unpublish' : 'Publish' ?></button>
                    </form>
                    &nbsp;
                    <form class="inline" method="POST" onsubmit="return confirm('Delete this document?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
                        <button type="submit" class="link-btn">Delete</button>
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
</body>
</html>

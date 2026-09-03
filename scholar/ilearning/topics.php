<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher']);
require_once __DIR__ . '/_ilearning_helpers.php';

$school_id = current_school_id();
$staff_id = (int) ($_SESSION['staff_id'] ?? 0);

$assigned_stmt = $pdo->prepare("
    SELECT DISTINCT ta.class_id, c.class_name, ta.subject_id, s.subject_name, s.subject_code
    FROM teacher_assignments ta
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    WHERE ta.school_id = ? AND ta.teacher_id = ?
    ORDER BY c.class_name, s.subject_name
");
$assigned_stmt->execute([$school_id, $staff_id]);
$my_assignments = $assigned_stmt->fetchAll();

$topics_stmt = $pdo->prepare("
    SELECT t.id, t.title, t.status, t.term, t.year, t.assessment_id, t.content_type, c.class_name, s.subject_name,
           (SELECT COUNT(*) FROM ilearning_progress p WHERE p.topic_id = t.id AND p.status = 'completed') AS completed_count,
           (SELECT COUNT(*) FROM students st WHERE st.class_id = t.class_id AND st.school_id = t.school_id) AS class_size
    FROM ilearning_topics t
    JOIN classes c ON c.id = t.class_id
    JOIN subjects s ON s.id = t.subject_id
    WHERE t.school_id = ? AND t.teacher_id = ?
    ORDER BY t.updated_at DESC
");
$topics_stmt->execute([$school_id, $staff_id]);
$topics = $topics_stmt->fetchAll();

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
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.header-actions{display:flex;gap:10px;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
a.btn-link:hover{background:rgba(0,168,168,0.1);}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:20px;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
th,td{text-align:left;padding:12px 20px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;background:rgba(255,255,255,0.02);}
tbody tr:last-child td{border-bottom:none;}
.empty{color:var(--muted);font-size:0.85rem;padding:24px;text-align:center;}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;text-transform:uppercase;}
.badge-draft{background:rgba(100,116,139,0.2);color:var(--muted);}
.badge-published{background:rgba(16,185,129,0.15);color:var(--green);}
.badge-archived{background:rgba(239,68,68,0.12);color:var(--danger);}
a.act{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:600;margin-right:12px;}
</style>
<div class="page-title-row">
    <h1>iLearning — My Topics</h1>
    <div class="header-actions">
        <a href="topic_edit.php" class="btn-link">+ New Topic</a>
    </div>
</div>

    <?php if (empty($my_assignments)): ?>
        <div class="section"><div class="empty">You have no class/subject assignments yet — ask your school admin to assign you via Teacher Assignments.</div></div>
    <?php endif; ?>

    <div class="section">
        <table>
            <thead>
                <tr><th>Title</th><th>Type</th><th>Class</th><th>Subject</th><th>Term</th><th>Status</th><th>Graded</th><th>Completion</th><th></th></tr>
            </thead>
            <tbody>
                <?php if (empty($topics)): ?>
                <tr><td colspan="8" class="empty">No topics yet — create your first one.</td></tr>
                <?php endif; ?>
                <?php foreach ($topics as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= ['written' => 'Written', 'pdf_notes' => 'PDF Notes', 'pdf_activity' => 'PDF Activity'][$t['content_type']] ?? 'Written' ?></td>
                    <td><?= htmlspecialchars($t['class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($t['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($t['term'] . ' ' . $t['year'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge badge-<?= strtolower($t['status']) ?>"><?= htmlspecialchars($t['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= $t['assessment_id'] ? 'Yes' : 'Practice only' ?></td>
                    <td><?= (int) $t['completed_count'] ?> / <?= (int) $t['class_size'] ?></td>
                    <td>
                        <a class="act" href="topic_edit.php?id=<?= (int) $t['id'] ?>">Edit</a>
                        <a class="act" href="questions.php?topic_id=<?= (int) $t['id'] ?>">Questions</a>
                        <a class="act" href="topic_roster.php?topic_id=<?= (int) $t['id'] ?>">Progress</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section">
        <div style="padding:14px 20px;">
            <a class="act" href="grade_open_answers.php">Grade Open (Short-Answer) Submissions →</a>
        </div>
    </div>
        </div>
    </div>
</div>
</body>
</html>

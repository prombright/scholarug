<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_library_helpers.php';

$school_id = current_school_id();
$student_id = current_student_id();

$stu_stmt = $pdo->prepare('SELECT class_id, full_name FROM students WHERE id = ? AND school_id = ?');
$stu_stmt->execute([$student_id, $school_id]);
$student = $stu_stmt->fetch();

$class_id = (int) ($student['class_id'] ?? 0);

$docs_stmt = $pdo->prepare("
    SELECT d.*, s.subject_name
    FROM library_documents d
    JOIN subjects s ON s.id = d.subject_id
    WHERE d.school_id = ? AND d.class_id = ? AND d.status = 'Published'
    ORDER BY s.subject_name, d.created_at DESC
");
$docs_stmt->execute([$school_id, $class_id]);
$all_docs = $docs_stmt->fetchAll();

$notes = array_filter($all_docs, static fn($d) => $d['category'] === 'notes');
$past_papers = array_filter($all_docs, static fn($d) => $d['category'] === 'past_paper');

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

$ACTIVE_NAV = 'library';
require_once __DIR__ . '/../_student_shell.php';
?>
<style>
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
h2.group-title{font-size:0.95rem;font-weight:700;margin:26px 0 12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
h2.group-title:first-of-type{margin-top:0;}
.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;}
.doc-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:18px;text-decoration:none;color:var(--text);transition:border-color .15s,transform .15s;display:block;}
.doc-card:hover{border-color:var(--cyan);transform:translateY(-2px);}
.doc-card .icon{font-size:1.6rem;color:var(--cyan);margin-bottom:10px;}
.doc-card .title{font-weight:700;font-size:0.9rem;margin-bottom:4px;}
.doc-card .meta{color:var(--muted);font-size:0.75rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:24px;text-align:center;background:var(--panel);border:1px solid var(--border);border-radius:10px;}
</style>
<div class="page-title-row">
    <h1>Library</h1>
</div>

<h2 class="group-title">Notes</h2>
<?php if (empty($notes)): ?>
    <div class="empty">No notes have been shared for your class yet.</div>
<?php else: ?>
    <div class="doc-grid">
        <?php foreach ($notes as $d): ?>
        <a class="doc-card" href="view.php?id=<?= (int) $d['id'] ?>">
            <div class="icon"><i class="bi bi-journal-text"></i></div>
            <div class="title"><?= htmlspecialchars($d['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="meta"><?= htmlspecialchars($d['subject_name'], ENT_QUOTES, 'UTF-8') ?><?= $d['term'] ? ' · ' . htmlspecialchars($d['term'] . ' ' . $d['year'], ENT_QUOTES, 'UTF-8') : '' ?></div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="group-title">Past Papers</h2>
<?php if (empty($past_papers)): ?>
    <div class="empty">No past papers have been shared for your class yet.</div>
<?php else: ?>
    <div class="doc-grid">
        <?php foreach ($past_papers as $d): ?>
        <a class="doc-card" href="view.php?id=<?= (int) $d['id'] ?>">
            <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
            <div class="title"><?= htmlspecialchars($d['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="meta"><?= htmlspecialchars($d['subject_name'], ENT_QUOTES, 'UTF-8') ?><?= $d['term'] ? ' · ' . htmlspecialchars($d['term'] . ' ' . $d['year'], ENT_QUOTES, 'UTF-8') : '' ?></div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

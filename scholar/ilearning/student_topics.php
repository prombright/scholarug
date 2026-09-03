<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);

$school_id = current_school_id();
$student_id = current_student_id();

$stuStmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
$stuStmt->execute([$student_id, $school_id]);
$class_id = (int) ($stuStmt->fetchColumn() ?: 0);

$stmt = $pdo->prepare("
    SELECT t.id, t.title, t.term, t.year, t.assessment_id, t.content_type, s.subject_name,
           p.percent_complete, p.status AS progress_status
    FROM ilearning_topics t
    JOIN subjects s ON s.id = t.subject_id
    LEFT JOIN ilearning_progress p ON p.topic_id = t.id AND p.student_id = ?
    WHERE t.school_id = ? AND t.class_id = ? AND t.status = 'Published'
    ORDER BY t.created_at DESC
");
$stmt->execute([$student_id, $school_id, $class_id]);
$topics = $stmt->fetchAll();

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
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.header-actions a{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;margin-left:14px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:18px;}
.card h3{margin:0 0 6px;font-size:1rem;}
.card .sub{color:var(--muted);font-size:0.75rem;margin-bottom:12px;}
.bar-bg{background:#111826;border-radius:6px;overflow:hidden;height:8px;margin-bottom:6px;}
.bar-fill{height:100%;background:var(--cyan);}
.badge{font-size:0.7rem;padding:2px 8px;border-radius:20px;background:rgba(16,185,129,0.15);color:var(--green);}
a.open{display:inline-block;margin-top:10px;color:var(--cyan);text-decoration:none;font-weight:700;font-size:0.85rem;}
.empty{color:var(--muted);text-align:center;padding:40px;}
</style>
<div class="page-title-row">
    <h1>iLearning — My Topics</h1>
    <div class="header-actions">
        <a href="practice.php">Practice Questions</a>
        <a href="live_sessions.php">Live Classes</a>
    </div>
</div>

    <?php if (empty($topics)): ?>
        <div class="empty">No topics published for your class yet.</div>
    <?php endif; ?>

    <div class="grid">
        <?php foreach ($topics as $t): ?>
        <div class="card">
            <h3><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <div class="sub">
                <?= htmlspecialchars($t['subject_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($t['term'] . ' ' . $t['year'], ENT_QUOTES, 'UTF-8') ?>
                <?= $t['assessment_id'] ? '<span class="badge">Graded</span>' : '' ?>
                <?php if ($t['content_type'] === 'pdf_notes'): ?><span class="badge" style="background:rgba(0,168,168,0.15);color:var(--cyan);">PDF Notes</span><?php endif; ?>
                <?php if ($t['content_type'] === 'pdf_activity'): ?><span class="badge" style="background:rgba(245,158,11,0.15);color:#f59e0b;">PDF Activity</span><?php endif; ?>
            </div>
            <div class="bar-bg"><div class="bar-fill" style="width:<?= (int) ($t['percent_complete'] ?? 0) ?>%;"></div></div>
            <div style="color:var(--muted);font-size:0.75rem;"><?= (int) ($t['percent_complete'] ?? 0) ?>% read<?= $t['progress_status'] === 'completed' ? ' · Completed' : '' ?></div>
            <a class="open" href="view_topic.php?id=<?= (int) $t['id'] ?>">Open →</a>
        </div>
        <?php endforeach; ?>
    </div>
        </div>
    </div>
</div>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher', 'student']);
require_once __DIR__ . '/_library_helpers.php';

$school_id = current_school_id();
$role = $_SESSION['role'];
$teacher_id = $role === 'teacher' ? current_staff_id() : null;
$student_id = $role === 'student' ? current_student_id() : null;

$doc_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT d.*, s.subject_name FROM library_documents d
    JOIN subjects s ON s.id = d.subject_id
    WHERE d.id = ?
");
$stmt->execute([$doc_id]);
$doc = $stmt->fetch();

if (!$doc || !library_can_access_document($pdo, $doc, $role, $school_id, $teacher_id, $student_id)) {
    http_response_code(403);
    die('This document is not available to you.');
}

$unread_message_count = 0;
if ($role === 'teacher') {
    $unread_msgs_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM conversation_messages cm
        JOIN conversations cv ON cv.id = cm.conversation_id
        WHERE cv.teacher_id = ? AND cv.school_id = ? AND cm.sender_role = 'student' AND cm.read_at IS NULL
    ");
    $unread_msgs_stmt->execute([$teacher_id, $school_id]);
    $unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

    $class_teacher_stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND class_teacher_id = ?");
    $class_teacher_stmt->execute([$school_id, $teacher_id]);
    $is_any_class_teacher = (int) $class_teacher_stmt->fetchColumn() > 0;
} else {
    $unread_msgs_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM conversation_messages cm
        JOIN conversations cv ON cv.id = cm.conversation_id
        WHERE cv.student_id = ? AND cv.school_id = ? AND cm.sender_role = 'teacher' AND cm.read_at IS NULL
    ");
    $unread_msgs_stmt->execute([$student_id, $school_id]);
    $unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

    require_once __DIR__ . '/../elections/_election_helpers.php';
    $votable_ballot = array_filter(
        election_approved_ballot_for_student($pdo, $school_id, $student_id),
        static fn($position) => !$position['already_voted']
    );
    $open_positions_to_vote = count($votable_ballot);
}

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'library';
if ($role === 'teacher') {
    require_once __DIR__ . '/../_teacher_shell.php';
} else {
    require_once __DIR__ . '/../_student_shell.php';
}
?>
<style>
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.meta{color:var(--muted);font-size:0.8rem;margin-bottom:20px;}
.pdf-pane{max-height:78vh;overflow-y:auto;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;}
</style>
<div class="page-title-row">
    <h1><?= htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8') ?></h1>
</div>
<div class="meta"><?= htmlspecialchars($doc['subject_name'], ENT_QUOTES, 'UTF-8') ?><?= $doc['term'] ? ' · ' . htmlspecialchars($doc['term'] . ' ' . $doc['year'], ENT_QUOTES, 'UTF-8') : '' ?> · <?= $doc['category'] === 'notes' ? 'Notes' : 'Past Paper' ?></div>

<div class="pdf-pane" id="pdfContainer"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script src="pdf_viewer.js"></script>
<script>
scholarRenderPdf({
    pdfUrl: 'serve_pdf.php?id=<?= (int) $doc['id'] ?>',
    containerEl: document.getElementById('pdfContainer')
});
</script>
        </div>
    </div>
</div>
</body>
</html>

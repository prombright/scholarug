<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['teacher', 'student']);
require_once __DIR__ . '/_ilearning_helpers.php';

$school_id = current_school_id();
$role = $_SESSION['role'];
$isTeacher = $role === 'teacher';
$error = '';

$addonActive = scholar_ilearning_addon_is_active($pdo, $school_id);

if ($isTeacher) {
    $staff_id = (int) ($_SESSION['staff_id'] ?? 0);

    $assigned_stmt = $pdo->prepare("
        SELECT ta.class_id, c.class_name, ta.subject_id, s.subject_name
        FROM teacher_assignments ta JOIN classes c ON ta.class_id = c.id JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.school_id = ? AND ta.teacher_id = ? ORDER BY c.class_name, s.subject_name
    ");
    $assigned_stmt->execute([$school_id, $staff_id]);
    $my_assignments = $assigned_stmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_session'])) {
        if (!$addonActive) {
            $error = 'Purchase the iLearning Live Classes add-on to schedule a session.';
        } else {
            $class_id = (int) ($_POST['class_id'] ?? 0);
            $subject_id = (int) ($_POST['subject_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $scheduled_at = trim($_POST['scheduled_at'] ?? '');
            $duration = max(10, (int) ($_POST['duration_minutes'] ?? 40));

            if (!ilearning_teacher_authorized($pdo, $school_id, $staff_id, $class_id, $subject_id)) {
                $error = 'You are not assigned to teach that class/subject.';
            } elseif ($title === '' || $scheduled_at === '') {
                $error = 'Title and scheduled time are required.';
            } else {
                $room = 'scholar-' . $school_id . '-' . bin2hex(random_bytes(8));
                $pdo->prepare(
                    "INSERT INTO ilearning_live_sessions (school_id, class_id, subject_id, teacher_id, title, scheduled_at, duration_minutes, room_reference)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$school_id, $class_id, $subject_id, $staff_id, $title, $scheduled_at, $duration, $room]);
            }
        }
    }

    $sessions_stmt = $pdo->prepare("
        SELECT ls.*, c.class_name, s.subject_name FROM ilearning_live_sessions ls
        JOIN classes c ON c.id = ls.class_id JOIN subjects s ON s.id = ls.subject_id
        WHERE ls.school_id = ? AND ls.teacher_id = ? ORDER BY ls.scheduled_at DESC
    ");
    $sessions_stmt->execute([$school_id, $staff_id]);
    $sessions = $sessions_stmt->fetchAll();
} else {
    $student_id = current_student_id();
    $stuStmt = $pdo->prepare('SELECT class_id FROM students WHERE id = ? AND school_id = ?');
    $stuStmt->execute([$student_id, $school_id]);
    $class_id = (int) ($stuStmt->fetchColumn() ?: 0);

    $sessions_stmt = $pdo->prepare("
        SELECT ls.*, s.subject_name, CONCAT(st.first_name, ' ', st.last_name) AS teacher_name
        FROM ilearning_live_sessions ls
        JOIN subjects s ON s.id = ls.subject_id
        JOIN staff st ON st.staff_id = ls.teacher_id
        WHERE ls.school_id = ? AND ls.class_id = ? AND ls.status IN ('scheduled','live')
        ORDER BY ls.scheduled_at ASC
    ");
    $sessions_stmt->execute([$school_id, $class_id]);
    $sessions = $sessions_stmt->fetchAll();
}

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'ilearning';
if ($isTeacher) {
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

    require_once __DIR__ . '/../_teacher_shell.php';
} else {
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

    require_once __DIR__ . '/../_student_shell.php';
}
?>
<style>
.page-title-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
.page-title-row h1{margin:0;font-size:1.2rem;font-weight:700;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:22px;margin-bottom:16px;}
.field{margin-bottom:14px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:6px;}
input,select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px;border-radius:6px;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;background:var(--cyan);color:#04121a;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(239,68,68,0.12);color:var(--danger);}
.upgrade-banner{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:var(--amber);padding:14px;border-radius:8px;margin-bottom:20px;font-size:0.85rem;}
.upgrade-banner a{color:var(--amber);font-weight:700;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
th,td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.badge{font-size:0.7rem;padding:2px 8px;border-radius:20px;font-weight:700;}
.badge-scheduled{background:rgba(0,168,168,0.15);color:var(--cyan);}
.badge-live{background:rgba(16,185,129,0.15);color:var(--green);}
.badge-ended{background:rgba(100,116,139,0.2);color:var(--muted);}
</style>
<div class="page-title-row">
    <h1>Live Classes</h1>
    <a href="<?= $isTeacher ? 'topics.php' : 'student_topics.php' ?>" style="color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;">&larr; iLearning</a>
</div>

    <?php if (!$addonActive): ?>
    <div class="upgrade-banner">
        This school hasn't purchased the iLearning Live Classes add-on yet.
        <a href="addon_upgrade.php">Upgrade now →</a>
    </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($isTeacher): ?>
    <div class="card">
        <h3 style="margin:0 0 14px;font-size:1rem;">Schedule a Session</h3>
        <form method="POST">
            <div class="field">
                <label>Class &amp; Subject</label>
                <select name="class_subject" onchange="var p=this.value.split('|');document.getElementById('cid').value=p[0];document.getElementById('sid').value=p[1];">
                    <option value="">-- Choose --</option>
                    <?php foreach ($my_assignments as $a): ?>
                        <option value="<?= (int) $a['class_id'] ?>|<?= (int) $a['subject_id'] ?>"><?= htmlspecialchars($a['class_name'] . ' — ' . $a['subject_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" id="cid" name="class_id"><input type="hidden" id="sid" name="subject_id">
            </div>
            <div class="field"><label>Title</label><input type="text" name="title" required></div>
            <div class="field"><label>Scheduled At</label><input type="datetime-local" name="scheduled_at" required></div>
            <div class="field"><label>Duration (minutes)</label><input type="number" name="duration_minutes" value="40"></div>
            <button type="submit" name="schedule_session" <?= $addonActive ? '' : 'disabled' ?>>Schedule</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <table>
            <thead><tr><th>Title</th><th><?= $isTeacher ? 'Class' : 'Teacher' ?></th><th>Subject</th><th>When</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($sessions)): ?><tr><td colspan="6" style="color:var(--muted);text-align:center;">No sessions yet.</td></tr><?php endif; ?>
            <?php foreach ($sessions as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($isTeacher ? $s['class_name'] : $s['teacher_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($s['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($s['scheduled_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge badge-<?= $s['status'] ?>"><?= htmlspecialchars($s['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td>
                    <?php if ($addonActive && $s['status'] !== 'ended' && $s['status'] !== 'canceled'): ?>
                        <a class="btn-link" href="live_session_room.php?id=<?= (int) $s['id'] ?>"><?= $isTeacher ? 'Start' : 'Join' ?></a>
                    <?php elseif (!$addonActive): ?>
                        <a class="btn-link" href="addon_upgrade.php">Upgrade to join</a>
                    <?php endif; ?>
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

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER ROLL CALL
|--------------------------------------------------------------------------
| Deliberately unrestricted by class: any teacher can take attendance for
| any class in their school, whether or not they're assigned to teach it
| (a teacher covering someone else's class, or the class teacher doing
| morning roll call, doesn't need a subject assignment to do that).
|
| Marks entry (teachers_portal.php) is the opposite -- restricted to
| classes/subjects the teacher is actually assigned to. That split is
| intentional, not an oversight.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/_attendance_helpers.php';

require_role(['teacher']);

$school_id  = current_school_id();
$staff_id   = current_staff_id();
$message    = '';
$message_type = '';

// ---------------------------------------------------------------------
// Save today's roll call -- whole-class, no subject. Same "general daily
// attendance" shape as school_admin/attendance.php's whole-class flow
// (subject_id left NULL, school_id set), sharing the one attendance
// table with the per-subject history already on file. Can't rely on
// attendance's uniq_attendance_entry(student_id, subject_id, date) key /
// ON DUPLICATE KEY UPDATE here the way the old per-subject version did --
// MySQL treats NULL subject_id as never equal to itself, so two saves on
// the same day would just insert a second row instead of updating the
// first. Explicit check-then-write instead, same pattern already proven
// in school_admin/attendance.php.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $class_id = (int) ($_POST['class_id'] ?? 0);
    $date     = $_POST['attendance_date'] ?? date('Y-m-d');
    $statuses = $_POST['status'] ?? []; // [student_id => status]

    if ($class_id && !empty($statuses)) {
        $touched = attendance_save($pdo, $school_id, $class_id, $staff_id, $date, $statuses);
        $message = 'Roll call saved for ' . $touched . ' student(s).';
        $message_type = 'success';
    } else {
        $message = 'Pick a class first.';
        $message_type = 'danger';
    }
}

// ---------------------------------------------------------------------
// Every class in the school -- not filtered by teacher_assignments
// ---------------------------------------------------------------------
$classes_stmt = $pdo->prepare("SELECT id, class_name, stream_name FROM classes WHERE school_id = ? ORDER BY class_name");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$sel_class = isset($_GET['class_id']) ? (int) $_GET['class_id'] : null;
$sel_date = $_GET['attendance_date'] ?? date('Y-m-d');
$roster = [];
$taken_at = null; // when this class's roll call for this date was first recorded

if ($sel_class) {
    $roster = attendance_fetch_roster($pdo, $school_id, $sel_class, $sel_date);
}

// Status counts for the clickable stat cards -- only meaningful once the
// roll call for this date has actually been recorded ($taken_at set),
// same "recorded yet or not" signal already used for the timestamp line.
$summary = attendance_summarize($roster);
$taken_at = $summary['taken_at'];
$status_counts = $summary['counts'];

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
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'attendance';
require_once __DIR__ . '/_teacher_shell.php';
?>
<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.filters{display:flex;gap:12px;flex-wrap:wrap;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:20px;}
select,input[type=date]{background:var(--panel);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;}
button{cursor:pointer;border:none;border-radius:6px;padding:8px 16px;font-weight:700;font-size:0.85rem;}
.btn-primary{background:var(--cyan);color:#04121a;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--border);}
.status-group{display:flex;gap:10px;flex-wrap:wrap;}
.status-group label{display:flex;align-items:center;gap:4px;font-size:0.78rem;color:var(--muted);cursor:pointer;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.stat-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:14px 16px;cursor:pointer;text-align:left;font-family:inherit;transition:border-color .15s,transform .1s;}
.stat-card:hover{border-color:var(--stat-color,var(--cyan));}
.stat-card.active{border-color:var(--stat-color,var(--cyan));background:color-mix(in srgb, var(--stat-color,var(--cyan)) 12%, var(--panel));}
.stat-card .n{font-size:1.6rem;font-weight:700;color:var(--stat-color,var(--cyan));}
.stat-card .label{color:var(--muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.stat-card.all{--stat-color:var(--text);}
tr.rc-row-hidden{display:none;}
</style>
<div class="page-title">Roll Call</div>

    <?php if ($message): ?><div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>

    <form method="GET" class="filters">
        <select name="class_id" onchange="this.form.submit()">
            <option value="">Choose class…</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $sel_class == $c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['class_name'] . ' ' . ($c['stream_name'] ?? ''), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="attendance_date" value="<?= htmlspecialchars($sel_date, ENT_QUOTES) ?>" onchange="this.form.submit()">
    </form>

    <?php if ($roster): ?>
    <?php if ($taken_at): ?>
        <p style="color:var(--muted);font-size:0.85rem;margin:-8px 0 16px;">Roll call taken at <?= htmlspecialchars(date('g:i a', strtotime($taken_at)), ENT_QUOTES) ?> on <?= htmlspecialchars(date('d M Y', strtotime($taken_at)), ENT_QUOTES) ?>.</p>

        <div class="stat-cards">
            <button type="button" class="stat-card all active" onclick="rcFilter('all', this)">
                <div class="n"><?= count($roster) ?></div>
                <div class="label">All</div>
            </button>
            <button type="button" class="stat-card" style="--stat-color:var(--green);" onclick="rcFilter('present', this)">
                <div class="n"><?= $status_counts['present'] ?></div>
                <div class="label">Present</div>
            </button>
            <button type="button" class="stat-card" style="--stat-color:var(--danger);" onclick="rcFilter('absent', this)">
                <div class="n"><?= $status_counts['absent'] ?></div>
                <div class="label">Absent</div>
            </button>
            <button type="button" class="stat-card" style="--stat-color:var(--amber);" onclick="rcFilter('sick', this)">
                <div class="n"><?= $status_counts['sick'] ?></div>
                <div class="label">Sick</div>
            </button>
            <button type="button" class="stat-card" style="--stat-color:var(--cyan);" onclick="rcFilter('permission', this)">
                <div class="n"><?= $status_counts['permission'] ?></div>
                <div class="label">Permission</div>
            </button>
        </div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="class_id" value="<?= $sel_class ?>">
        <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($sel_date, ENT_QUOTES) ?>">
        <table>
            <thead><tr><th>Student</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($roster as $r): $current = $r['status'] ?? 'present'; ?>
                <tr data-status="<?= htmlspecialchars($current, ENT_QUOTES) ?>">
                    <td><?= htmlspecialchars($r['full_name'], ENT_QUOTES) ?> <span style="color:var(--muted);">(<?= htmlspecialchars($r['student_no'] ?? '—', ENT_QUOTES) ?>)</span></td>
                    <td>
                        <div class="status-group">
                            <?php foreach (['present' => 'Present', 'absent' => 'Absent', 'sick' => 'Sick', 'permission' => 'Permission'] as $val => $label): ?>
                                <label>
                                    <input type="radio" name="status[<?= $r['id'] ?>]" value="<?= $val ?>" <?= $current === $val ? 'checked' : '' ?>>
                                    <?= $label ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:16px;"><button type="submit" name="save_attendance" class="btn-primary">Save Roll Call</button></div>
    </form>
    <?php elseif ($sel_class): ?>
        <p style="color:var(--muted);">No students found in this class.</p>
    <?php else: ?>
        <p style="color:var(--muted);">Pick a class and date to take the register.</p>
    <?php endif; ?>
        </div>
    </div>
</div>
<script>
function rcFilter(status, btn) {
    document.querySelectorAll('.stat-card').forEach(function (c) { c.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('table tbody tr').forEach(function (row) {
        row.classList.toggle('rc-row-hidden', status !== 'all' && row.dataset.status !== status);
    });
}
</script>
</body>
</html>

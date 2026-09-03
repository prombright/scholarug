<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ASSIGN STUDENTS TO AN ELECTIVE (subject-first view)
|--------------------------------------------------------------------------
| Pick a class, then one of its Elective subjects, then tick which
| students in that class actually take it. The per-student counterpart
| is student_subjects.php ("click a student, tick their subjects") --
| both write to the same student_subjects table. See that file and
| _setup/student_subjects.sql for the shared design notes (dot/case
| class_name normalization, replace-set semantics, why this table is
| safe to fully rewrite unlike subjects.id).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_subject_enrollment_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();
$message = '';
$message_type = '';

$sel_class_id   = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$sel_subject_id = (int) ($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);

$state = admin_subject_enrollment_load($pdo, $school_id, $sel_class_id, $sel_subject_id);
$classes = $state['classes'];
$sel_class = $state['sel_class'];
$electives = $state['electives'];
$sel_subject = $state['sel_subject'];
$students = $state['students'];
$enrolled_ids = $state['enrolled_ids'];

// ---- Save ticked students (full replace-set for this subject) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_students']) && $sel_class && $sel_subject) {
    $save_result = admin_subject_enrollment_save($pdo, $school_id, $sel_class, $sel_subject, $_POST['student_ids'] ?? []);
    $message = $save_result['message'];
    $message_type = $save_result['ok'] ? 'success' : 'error';

    if ($save_result['ok']) {
        $state = admin_subject_enrollment_load($pdo, $school_id, $sel_class_id, $sel_subject_id);
        $students = $state['students'];
        $enrolled_ids = $state['enrolled_ids'];
    }
}

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'subjects';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.picker-row{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:6px;}
.picker-row > div{flex:1;min-width:200px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;}
.student-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin:20px 0;}
.student-tile{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:0.85rem;}
.student-tile input[type=checkbox]{width:auto;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
.empty{color:var(--muted);font-size:0.85rem;}
</style>
<main class="main-content">
<div class="page-inner">
    <h1 style="font-size:1.4rem;">Assign Students to an Elective</h1>
    <p style="color:var(--muted);font-size:0.85rem;margin-top:-8px;">Pick a class and elective, then tick who takes it. Prefer doing this per-student instead? Use the <a href="students.php" style="color:#00A8A8;">Students list</a>'s "Subjects" link on any student.</p>

    <?php if ($message): ?><div class="alert <?= $message_type ?>"><?= htmlspecialchars($message, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="section">
        <form method="get">
            <div class="picker-row">
                <div>
                    <label>Class</label>
                    <select name="class_id" onchange="this.form.submit()">
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $sel_class && (int) $sel_class['id'] === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['class_name'], ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($sel_class): ?>
                <div>
                    <label>Elective Subject</label>
                    <select name="subject_id" onchange="this.form.submit()">
                        <option value="">-- Select Elective --</option>
                        <?php foreach ($electives as $e): ?>
                            <option value="<?= (int) $e['id'] ?>" <?= $sel_subject && (int) $sel_subject['id'] === (int) $e['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['subject_name'] . ' (' . $e['subject_code'] . ')', ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="class_id" value="<?= (int) $sel_class['id'] ?>">
                <?php endif; ?>
            </div>
        </form>

        <?php if ($sel_class && empty($electives)): ?>
            <p class="empty" style="margin-top:14px;">No elective subjects exist for <?= htmlspecialchars($sel_class['class_name'], ENT_QUOTES) ?> yet. Add one via <a href="subject_matrix.php" style="color:#00A8A8;">Subject Matrix</a>, marking it Elective.</p>
        <?php endif; ?>

        <?php if ($sel_class && $sel_subject): ?>
            <form method="post">
                <input type="hidden" name="class_id" value="<?= (int) $sel_class['id'] ?>">
                <input type="hidden" name="subject_id" value="<?= (int) $sel_subject['id'] ?>">
                <?php if (empty($students)): ?>
                    <p class="empty">No students in this class yet.</p>
                <?php else: ?>
                    <div class="student-grid">
                        <?php foreach ($students as $s): ?>
                            <label class="student-tile">
                                <input type="checkbox" name="student_ids[]" value="<?= (int) $s['id'] ?>"
                                       <?= in_array((int) $s['id'], $enrolled_ids, true) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($s['full_name'], ENT_QUOTES) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="save_students" value="1">Save Enrollment</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>

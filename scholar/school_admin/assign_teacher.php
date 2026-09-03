<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER ASSIGNMENTS
|--------------------------------------------------------------------------
| Pick a teacher, then:
|  - toggle which departments they belong to (staff_departments)
|  - add/remove specific subject+class teaching assignments (teacher_assignments)
|  - optionally elevate their account role (e.g. to DOS)
|
| Deliberately built on the clean tables (staff, classes, subjects,
| teacher_assignments) rather than the older system_teacher_assignments /
| "coursework assignments" tables, which are a separate, older feature.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/_assign_teacher_helpers.php';

require_role(['school_admin']);

$school_id = current_school_id();
$error = '';
$success = '';

$selected_staff_id = (int) ($_GET['staff_id'] ?? $_POST['staff_id'] ?? 0);

// ---- Save department memberships ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_departments'])) {
    admin_assign_save_departments($pdo, $school_id, $selected_staff_id, array_map('intval', $_POST['department_ids'] ?? []));
    $success = 'Departments updated.';
}

// ---- Add a subject+class assignment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_assignment'])) {
    $result = admin_assign_add_assignment(
        $pdo, $school_id, $selected_staff_id,
        (int) ($_POST['subject_id'] ?? 0), (int) ($_POST['class_id'] ?? 0),
        (int) ($_POST['periods_per_week'] ?? 5), (int) ($_POST['paper_number'] ?? 1)
    );
    if ($result['ok']) { $success = $result['message']; } else { $error = $result['message']; }
}

// ---- Update how many periods/week an existing assignment needs ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_periods'])) {
    admin_assign_update_periods($pdo, $school_id, $selected_staff_id, (int) ($_POST['assignment_id'] ?? 0), (int) ($_POST['periods_per_week'] ?? 5));
    $success = 'Periods/week updated.';
}

// ---- Remove a subject+class assignment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_assignment'])) {
    admin_assign_remove_assignment($pdo, $school_id, $selected_staff_id, (int) ($_POST['assignment_id'] ?? 0));
    $success = 'Assignment removed.';
}

// ---- Change primary role (e.g. promote to DOS) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    $result = admin_assign_change_role($pdo, $school_id, $selected_staff_id, $_POST['role'] ?? '');
    if ($result['ok']) { $success = $result['message']; }
}

// ---- Data for the page ----
$teachers = admin_assign_fetch_teachers($pdo, $school_id);
$departments = admin_assign_fetch_departments($pdo, $school_id);
$subjects = admin_assign_fetch_subjects($pdo, $school_id);

// subjects is still one row per class+subject combo under the hood (papers
// count genuinely varies by level, e.g. Biology has 2 papers at S.3/S.4),
// but the picker below shouldn't nag the admin with "Biology (S.1)",
// "Biology (S.2)" ... as separate entries -- group by name here so the
// dropdown shows "Biology" once, and let choosing a class resolve back to
// the right underlying row via JS (see $subjects_by_name below).
$subjects_by_name = admin_assign_subjects_by_name($subjects);
$subject_names = array_keys($subjects_by_name);
sort($subject_names, SORT_STRING);

$classes = admin_assign_fetch_classes($pdo, $school_id);

$selected_teacher = null;
$their_departments = [];
$their_assignments = [];
$their_current_role = null;

if ($selected_staff_id > 0) {
    $__detail = admin_assign_fetch_teacher_detail($pdo, $school_id, $teachers, $selected_staff_id);
    $selected_teacher = $__detail['teacher'];
    $their_departments = $__detail['departments'];
    $their_assignments = $__detail['assignments'];
    $their_current_role = $__detail['current_role'];
}

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'assignments';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
select,input{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
.danger-btn{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:6px 12px;font-size:0.75rem;margin-top:0;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.dept-check{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:0.85rem;}
.dept-check input{width:auto;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.row{display:flex;gap:12px;}
.row > div{flex:1;}
.empty{color:var(--muted);font-size:0.85rem;}
</style>
    <main class="main-content">
    <div class="page-inner">
        <h1 style="font-size:1.4rem;">Teacher Assignments</h1>

        <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

        <div class="section">
            <label>Select a Teacher</label>
            <select onchange="window.location.href='assign_teacher.php?staff_id='+this.value">
                <option value="">-- Choose --</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int) $t['staff_id'] ?>" <?= $selected_staff_id === (int)$t['staff_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name'], ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($selected_teacher): ?>

        <div class="section">
            <h2 style="font-size:1rem;margin:0 0 14px;">Departments</h2>
            <form method="post">
                <input type="hidden" name="staff_id" value="<?= $selected_staff_id ?>">
                <?php foreach ($departments as $d): ?>
                    <label class="dept-check">
                        <input type="checkbox" name="department_ids[]" value="<?= (int)$d['id'] ?>"
                            <?= in_array((int)$d['id'], $their_departments, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($d['department_name'], ENT_QUOTES) ?>
                    </label>
                <?php endforeach; ?>
                <?php if (!$departments): ?><div class="empty">No departments created yet.</div><?php endif; ?>
                <button type="submit" name="save_departments" value="1">Save Departments</button>
            </form>
        </div>

        <div class="section">
            <h2 style="font-size:1rem;margin:0 0 14px;">Teaching Assignments</h2>
            <table style="margin-bottom:18px;">
                <tr><th>Subject</th><th>Paper</th><th>Class</th><th>Stream</th><th>Periods/Week</th><th></th></tr>
                <?php foreach ($their_assignments as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['subject_name'], ENT_QUOTES) ?></td>
                    <td><?= (int) $a['papers_count'] > 1 ? 'Paper ' . (int) $a['paper_number'] : '—' ?></td>
                    <td><?= htmlspecialchars($a['class_name'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($a['stream_name'] ?? '—', ENT_QUOTES) ?></td>
                    <td>
                        <form method="post" style="margin:0;display:flex;gap:6px;align-items:center;">
                            <input type="hidden" name="staff_id" value="<?= $selected_staff_id ?>">
                            <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                            <input type="number" name="periods_per_week" min="1" max="15" value="<?= (int) $a['periods_per_week'] ?>" style="width:60px;padding:6px;">
                            <button type="submit" name="update_periods" value="1" style="margin:0;padding:6px 10px;font-size:0.75rem;">Save</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="staff_id" value="<?= $selected_staff_id ?>">
                            <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" name="remove_assignment" value="1" class="danger-btn">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$their_assignments): ?><tr><td colspan="6" class="empty">No teaching assignments yet.</td></tr><?php endif; ?>
            </table>

            <form method="post">
                <input type="hidden" name="staff_id" value="<?= $selected_staff_id ?>">
                <div class="row">
                    <div>
                        <label>Subject</label>
                        <select id="assignSubjectNameSelect" required onchange="onAssignSubjectNameChange()">
                            <option value="">-- Choose --</option>
                            <?php foreach ($subject_names as $name): ?>
                                <option value="<?= htmlspecialchars($name, ENT_QUOTES) ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="subject_id" id="assignSubjectIdHidden">
                    </div>
                    <div>
                        <label>Class</label>
                        <select name="class_id" id="assignClassSelect" required disabled onchange="onAssignClassChange()">
                            <option value="">-- Choose subject first --</option>
                        </select>
                    </div>
                    <div id="assignPaperPickerWrap" style="display:none;">
                        <label>Paper</label>
                        <select name="paper_number" id="assignPaperPicker"></select>
                    </div>
                    <div>
                        <label>Periods/Week</label>
                        <input type="number" name="periods_per_week" min="1" max="15" value="5" required>
                    </div>
                </div>
                <script>
                const ASSIGN_SUBJECTS_BY_NAME = <?= json_encode($subjects_by_name, JSON_HEX_TAG) ?>;
                const ASSIGN_ALL_CLASSES = <?= json_encode($classes, JSON_HEX_TAG) ?>;

                function onAssignSubjectNameChange() {
                    var subjectName = document.getElementById('assignSubjectNameSelect').value;
                    var classSel = document.getElementById('assignClassSelect');
                    var byClassName = ASSIGN_SUBJECTS_BY_NAME[subjectName] || {};

                    classSel.innerHTML = '<option value="">-- Choose --</option>';
                    classSel.disabled = !subjectName;

                    ASSIGN_ALL_CLASSES.forEach(function (c) {
                        if (!byClassName[c.class_name]) return; // this class doesn't offer the subject
                        var o = document.createElement('option');
                        o.value = c.id;
                        o.setAttribute('data-class-name', c.class_name);
                        o.textContent = c.class_name + (c.stream_name ? ' ' + c.stream_name : '');
                        classSel.appendChild(o);
                    });

                    onAssignClassChange();
                }

                function onAssignClassChange() {
                    var subjectName = document.getElementById('assignSubjectNameSelect').value;
                    var classSel = document.getElementById('assignClassSelect');
                    var opt = classSel.options[classSel.selectedIndex];
                    var className = opt ? opt.getAttribute('data-class-name') : null;
                    var entry = (subjectName && className && ASSIGN_SUBJECTS_BY_NAME[subjectName]) ? ASSIGN_SUBJECTS_BY_NAME[subjectName][className] : null;

                    document.getElementById('assignSubjectIdHidden').value = entry ? entry.id : '';
                    updateAssignPaperPicker(entry ? entry.papers_count : 1);
                }

                function updateAssignPaperPicker(papersCount) {
                    var wrap = document.getElementById('assignPaperPickerWrap');
                    var picker = document.getElementById('assignPaperPicker');
                    if (papersCount > 1) {
                        picker.innerHTML = '';
                        for (var i = 1; i <= papersCount; i++) {
                            var o = document.createElement('option');
                            o.value = i;
                            o.textContent = 'Paper ' + i;
                            picker.appendChild(o);
                        }
                        wrap.style.display = '';
                    } else {
                        wrap.style.display = 'none';
                        picker.innerHTML = '';
                    }
                }
                </script>
                <button type="submit" name="add_assignment" value="1">Add Assignment</button>
            </form>
        </div>

        <div class="section">
            <h2 style="font-size:1rem;margin:0 0 14px;">Role</h2>
            <?php if ($their_current_role === null): ?>
                <div class="empty">This staff member doesn't have a portal login yet — create one from Manage Teachers first.</div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="staff_id" value="<?= $selected_staff_id ?>">
                    <label>Primary Role (one at a time — class teacher is set separately, on the Classes page)</label>
                    <select name="role">
                        <?php foreach (['teacher', 'dos', 'headteacher', 'bursar'] as $r): ?>
                            <option value="<?= $r ?>" <?= $their_current_role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="change_role" value="1">Update Role</button>
                </form>
            <?php endif; ?>
        </div>

        <?php endif; ?>

    </div>
    </main>
</div><!-- /.app-shell -->
</body>
</html>
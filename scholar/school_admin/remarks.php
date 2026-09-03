<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — REPORT CARD REMARKS
|--------------------------------------------------------------------------
| Class Teacher's Remark / Head Teacher's Remark, shown on the report card
| (see _report_card_render.php) alongside the auto-computed grade summary.
| The two remark columns are written by different roles, so this one page
| serves both, gating which textarea is editable per role/class instead of
| splitting into two pages:
|   - teacher: class_teacher_remark only, only for a class they're the
|     class_teacher_id of (mirrors is_class_teacher_of() elsewhere).
|   - headteacher / dos: head_teacher_remark only, any class.
|   - school_admin: both, any class (same admin-override precedent as the
|     rest of this app).
|
| Standalone page (not wrapped in _admin_shell.php), same reasoning as
| bulk_report_print.php -- teachers need it too, not just school_admin.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../_report_card_render.php';

require_role(['school_admin', 'headteacher', 'dos', 'teacher']);

$school_id = current_school_id();
$role = $_SESSION['role'];
$is_teacher = $role === 'teacher';
$can_write_class_remark = $is_teacher || $role === 'school_admin';
$can_write_head_remark = in_array($role, ['headteacher', 'dos', 'school_admin'], true);
$error = '';
$message = '';

if ($is_teacher) {
    $classesStmt = $pdo->prepare("
        SELECT id, class_name, stream_name FROM classes
        WHERE school_id = ? AND class_teacher_id = ?
        ORDER BY class_name, stream_name
    ");
    $classesStmt->execute([$school_id, current_staff_id()]);
} else {
    $classesStmt = $pdo->prepare("
        SELECT id, class_name, stream_name FROM classes
        WHERE school_id = ?
        ORDER BY FIELD(class_name, 'S.1','S.2','S.3','S.4','S.5','S.6'), stream_name
    ");
    $classesStmt->execute([$school_id]);
}
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);
$allowed_ids = array_map('intval', array_column($classes, 'id'));

$sel_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;
$term = $_GET['term'] ?? current_term();
$year = (int) ($_GET['year'] ?? current_year());

// ---- Save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_remarks'])) {
    $post_class_id = (int) ($_POST['class_id'] ?? 0);
    $post_term = trim($_POST['term'] ?? '');
    $post_year = (int) ($_POST['year'] ?? 0);

    if (!in_array($post_class_id, $allowed_ids, true) || $post_term === '' || $post_year < 1) {
        $error = 'Invalid class/term/year -- nothing was saved.';
    } else {
        $studentsStmt = $pdo->prepare("SELECT id FROM students WHERE school_id = ? AND class_id = ?");
        $studentsStmt->execute([$school_id, $post_class_id]);
        $valid_student_ids = array_map('intval', array_column($studentsStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        $upsertClass = $pdo->prepare("
            INSERT INTO report_card_remarks (school_id, student_id, term, year, class_teacher_remark, class_teacher_updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE class_teacher_remark = VALUES(class_teacher_remark), class_teacher_updated_by = VALUES(class_teacher_updated_by)
        ");
        $upsertHead = $pdo->prepare("
            INSERT INTO report_card_remarks (school_id, student_id, term, year, head_teacher_remark, head_teacher_updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE head_teacher_remark = VALUES(head_teacher_remark), head_teacher_updated_by = VALUES(head_teacher_updated_by)
        ");

        $saved = 0;
        $remarks_in = $_POST['remarks'] ?? [];
        if (is_array($remarks_in)) {
            foreach ($remarks_in as $sid => $fields) {
                $sid = (int) $sid;
                if (!in_array($sid, $valid_student_ids, true) || !is_array($fields)) {
                    continue;
                }
                if ($can_write_class_remark && array_key_exists('class_teacher_remark', $fields)) {
                    $upsertClass->execute([$school_id, $sid, $post_term, $post_year, trim((string) $fields['class_teacher_remark']), current_staff_id()]);
                    $saved++;
                }
                if ($can_write_head_remark && array_key_exists('head_teacher_remark', $fields)) {
                    $upsertHead->execute([$school_id, $sid, $post_term, $post_year, trim((string) $fields['head_teacher_remark']), current_staff_id()]);
                    $saved++;
                }
            }
        }
        $message = $saved > 0 ? 'Remarks saved.' : 'Nothing to save.';
        $sel_class = $post_class_id;
        $term = $post_term;
        $year = $post_year;
    }
}

$students = [];
$class_label = '';
if ($sel_class !== null) {
    if (!in_array($sel_class, $allowed_ids, true)) {
        $error = 'You do not have access to that class.';
        $sel_class = null;
    } else {
        foreach ($classes as $c) {
            if ((int) $c['id'] === $sel_class) {
                $class_label = $c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '');
            }
        }

        $studentsStmt = $pdo->prepare("SELECT id, full_name FROM students WHERE school_id = ? AND class_id = ? ORDER BY full_name ASC");
        $studentsStmt->execute([$school_id, $sel_class]);
        $roster = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

        $student_ids = array_map('intval', array_column($roster, 'id'));
        $existing = scholar_fetch_class_report_remarks($pdo, $school_id, $student_ids, $term, $year);

        foreach ($roster as $s) {
            $sid = (int) $s['id'];
            $students[] = [
                'id' => $sid,
                'full_name' => $s['full_name'],
                'class_teacher_remark' => $existing[$sid]['class_teacher_remark'] ?? '',
                'head_teacher_remark' => $existing[$sid]['head_teacher_remark'] ?? '',
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report Card Remarks — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.toolbar{max-width:1100px;margin:0 auto;padding:32px 20px 60px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.3rem;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
a.btn-link:hover{background:rgba(0,168,168,0.1);}
.report-tabs{display:flex;gap:8px;margin-bottom:20px;}
.report-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.report-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.filters{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.filters .row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:14px;align-items:end;}
@media(max-width:768px){ .filters .row{grid-template-columns:1fr;} }
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select,input[type=text],input[type=number],textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;}
textarea{resize:vertical;min-height:56px;}
textarea:disabled{opacity:0.5;cursor:not-allowed;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-error{background:rgba(239,68,68,0.12);color:var(--danger);}
.alert-ok{background:rgba(16,185,129,0.12);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.roster-table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.roster-table th{background:var(--bg);color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;padding:12px;text-align:left;border-bottom:1px solid var(--border);}
.roster-table td{padding:12px;border-bottom:1px solid var(--border);vertical-align:top;}
.roster-table tr:last-child td{border-bottom:none;}
.student-name{font-weight:700;white-space:nowrap;padding-top:16px;}
.save-bar{margin-top:16px;display:flex;justify-content:flex-end;}
.hint{color:var(--muted);font-size:0.75rem;margin-top:6px;}
</style>
</head>
<body>
<?php include __DIR__ . '/../preloader.php'; ?>
<div class="toolbar">
    <div class="header">
        <h1>Report Card Remarks</h1>
        <a href="<?= $is_teacher ? '../teachers_portal.php' : '../school_admin/school_admin_dashboard.php' ?>" class="btn-link">&larr; Back</a>
    </div>

    <?php if (!$is_teacher): ?>
        <div class="report-tabs">
            <a href="grading_scales.php">Grading &amp; Bands</a>
            <a href="report_settings.php">Display Settings</a>
            <a href="bulk_report_print.php">Print Reports</a>
            <a href="remarks.php" class="active">Remarks</a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <?php if (empty($classes)): ?>
        <p class="empty"><?= $is_teacher ? 'You are not the class teacher of any class yet.' : 'No classes set up for this school yet.' ?></p>
    <?php else: ?>
    <div class="filters">
        <form method="GET">
            <div class="row">
                <div>
                    <label>Class</label>
                    <select name="class_id" required>
                        <option value="">-- Choose Class --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $sel_class === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Term</label>
                    <select name="term">
                        <?php foreach (['Term 1', 'Term 2', 'Term 3'] as $t): ?>
                            <option value="<?= $t ?>" <?= $term === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Year</label>
                    <input type="number" name="year" value="<?= htmlspecialchars((string) $year) ?>">
                </div>
                <div>
                    <button type="submit">Load Class</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($sel_class !== null): ?>
        <?php if (!empty($students)): ?>
            <form method="POST">
                <input type="hidden" name="class_id" value="<?= $sel_class ?>">
                <input type="hidden" name="term" value="<?= htmlspecialchars($term) ?>">
                <input type="hidden" name="year" value="<?= htmlspecialchars((string) $year) ?>">

                <table class="roster-table">
                    <thead>
                        <tr>
                            <th style="width:16%;">Student</th>
                            <th style="width:42%;">Class Teacher's Remark</th>
                            <th style="width:42%;">Head Teacher's Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr>
                            <td class="student-name"><?= htmlspecialchars($s['full_name']) ?></td>
                            <td>
                                <textarea name="remarks[<?= $s['id'] ?>][class_teacher_remark]" <?= $can_write_class_remark ? '' : 'disabled' ?> placeholder="<?= $can_write_class_remark ? 'e.g. Good effort this term...' : 'Only the class teacher can edit this.' ?>"><?= htmlspecialchars($s['class_teacher_remark']) ?></textarea>
                            </td>
                            <td>
                                <textarea name="remarks[<?= $s['id'] ?>][head_teacher_remark]" <?= $can_write_head_remark ? '' : 'disabled' ?> placeholder="<?= $can_write_head_remark ? 'e.g. Keep up the good work...' : 'Only the head teacher can edit this.' ?>"><?= htmlspecialchars($s['head_teacher_remark']) ?></textarea>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($can_write_class_remark || $can_write_head_remark): ?>
                <div class="save-bar">
                    <button type="submit" name="save_remarks" value="1">Save Remarks</button>
                </div>
                <?php else: ?>
                <p class="hint">Your role does not have write access to either remark column here.</p>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <p class="empty">No students found in this class.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
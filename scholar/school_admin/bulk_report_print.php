<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — BULK REPORT CARD PRINTING
|--------------------------------------------------------------------------
| Print every student's report card for one class in a single pass,
| instead of opening generate_report.php one student at a time.
|
| Standalone page (not wrapped in _admin_shell.php) since DOS, headteacher,
| and class teachers need it too, not just school_admin. A 'teacher' only
| sees classes they're the class_teacher_id of -- mirrors the
| class-dropdown-fan-out pattern in message_parents.php, but scoped by
| classes.id (not the denormalized students.class_name) since we need
| class_teacher_id for that role check.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../_report_card_render.php';
require_once __DIR__ . '/_students_helpers.php';

// The one page in the app that renders a real loop of work in a single
// request. Shared hosting commonly caps max_execution_time at 30s -- fine
// after the class-batch query optimization below for a normal class, but
// a very large class (or a slow moment on a shared DB) shouldn't hard-fail
// with a blank timeout page. @ silences the warning on hosts that disable
// set_time_limit() entirely (some do, as a hosting-level safety policy).
@set_time_limit(120);

require_role(['school_admin', 'headteacher', 'dos', 'teacher']);

$school_id = current_school_id();
$is_teacher = $_SESSION['role'] === 'teacher';
$error = '';

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

$sel_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;
$term = $_GET['term'] ?? current_term();
$year = (int) ($_GET['year'] ?? current_year());

$reports = [];
$class_label = '';

if ($sel_class !== null) {
    $allowed_ids = array_map('intval', array_column($classes, 'id'));
    if (!in_array($sel_class, $allowed_ids, true)) {
        $error = 'You do not have access to print reports for that class.';
        $sel_class = null;
    } else {
        $sel_class_name = '';
        foreach ($classes as $c) {
            if ((int) $c['id'] === $sel_class) {
                $class_label = $c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '');
                $sel_class_name = $c['class_name'];
            }
        }
        // A class is level-homogeneous (S.1-S.4 vs S.5-S.6), so the whole
        // batch fetches ONE grading scale, same as admin_student_level_type()
        // decides at student-creation time -- keeps this in lockstep with
        // whichever scale render_report_card_html() picks per student.
        $class_level_type = admin_student_level_type($sel_class_name) === 'A-Level' ? 'A-Level' : 'O-Level';

        $school_stmt = $pdo->prepare("SELECT school_name, school_badge, phone_contact, email_contact, address FROM schools WHERE id = ?");
        $school_stmt->execute([$school_id]);
        $school = $school_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $studentsStmt = $pdo->prepare("SELECT id FROM students WHERE school_id = ? AND class_id = ? ORDER BY full_name ASC");
        $studentsStmt->execute([$school_id, $sel_class]);
        $student_ids = array_map('intval', array_column($studentsStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        // Printing a whole class used to run render_report_card_html()'s
        // full per-subject query set (marks aggregate + grade lookup, twice
        // over for A-Level's UACE points) once per student -- 800-1,600
        // queries for one 40-student class. Pre-fetching every student's
        // weighted scores and the school's grading scale ONCE up front,
        // then passing that batch into render_report_card_html(), cuts
        // this to a handful of queries for the whole class instead of per
        // student. generate_report.php (single-student printing) isn't
        // touched -- $classBatch is a new optional parameter there too,
        // left unused, so nothing about it changes.
        $classBatch = [
            'grading_scales'  => scholar_fetch_grading_scales($pdo, $school_id, $class_level_type),
            'weighted_scores' => scholar_fetch_class_weighted_scores($pdo, $school_id, $student_ids, $term, $year),
            'draft_subjects'  => scholar_fetch_class_draft_subjects($pdo, $school_id, $student_ids, $term, $year),
            'remarks'         => scholar_fetch_class_report_remarks($pdo, $school_id, $student_ids, $term, $year),
        ];
        $reportSettings = scholar_fetch_report_settings($pdo, $school_id);

        foreach ($student_ids as $sid) {
            $r = render_report_card_html($pdo, $school, $school_id, $sid, $term, $year, $classBatch, $reportSettings);
            if ($r['found']) {
                $reports[] = ['id' => $sid, 'name' => $r['student_name'], 'html' => $r['html']];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bulk Print Reports — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.toolbar{max-width:1200px;margin:0 auto;padding:32px 20px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.3rem;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
a.btn-link:hover{background:rgba(0,168,168,0.1);}
.filters{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.filters .row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:14px;align-items:end;}
@media(max-width:768px){ .filters .row{grid-template-columns:1fr;} }
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select,input[type=text],input[type=number]{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(239,68,68,0.12);color:var(--danger);}
.summary{color:var(--muted);font-size:0.85rem;margin-bottom:16px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.report-tabs{display:flex;gap:8px;margin-bottom:20px;}
.report-tabs a{padding:9px 18px;border-radius:8px;border:1px solid var(--border);color:var(--muted);text-decoration:none;font-size:0.85rem;font-weight:600;}
.report-tabs a.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}

/* Student roster as a single alphabetical column on the left (names are
   already ORDER BY full_name ASC from the query), report preview on the
   right instead of stacked below it -- same "click to preview" idea as
   the old horizontal strip, just laid out so picking a name and reading
   their report card happen side by side instead of scroll-down-to-see. */
.report-layout{display:flex;gap:24px;align-items:flex-start;}
.roster-col{flex:0 0 250px;position:sticky;top:20px;max-height:calc(100vh - 40px);overflow-y:auto;padding-right:4px;}
.roster-search{width:100%;margin-bottom:10px;}
.roster-list{display:flex;flex-direction:column;gap:6px;}
.roster-list button{text-align:left;width:100%;padding:10px 14px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:0.85rem;font-weight:600;cursor:pointer;}
.roster-list button.active{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.roster-list button.hidden-by-search{display:none;}
.preview-col{flex:1;min-width:0;}
.preview-actions{display:flex;gap:8px;margin-bottom:16px;}
.preview-actions button{background:transparent;border:1px solid rgba(0,168,168,0.4);color:var(--cyan);}
.preview-actions button:first-child{background:var(--cyan);color:#04121a;border-color:var(--cyan);}
.report-panel{display:none;}
.report-panel.active{display:block;}
@media(max-width:900px){
    .report-layout{flex-direction:column;}
    .roster-col{flex:0 0 auto;width:100%;position:static;max-height:none;}
    .roster-list{flex-direction:row;flex-wrap:wrap;}
    .roster-list button{width:auto;}
}
@media print {
    body.print-all .report-panel{display:block !important;}
    .report-layout{display:block;}
    .roster-col, .roster-search, .preview-actions{display:none !important;}
}

/* Report card styling -- shared with generate_report.php via
   _report_card_style.php, so the two can no longer silently drift.
   Page-specific overrides for stacking multiple cards with page breaks
   stay here, layered on top of the shared base. */
<?php include __DIR__ . '/../_report_card_style.php'; ?>
.report-card-wrapper { margin: 0 auto 40px; background:#fff; color:#1e293b; font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; line-height: 1.4; page-break-after: always; }

@media print {
    body { background: #fff; }
    /* .toolbar wraps the whole page, including .report-layout where the
       actual report cards live -- hiding the whole div (as this used to)
       hid the reports along with the chrome around them, printing a
       blank page. Hide only the chrome children, not the ancestor. */
    .toolbar > .header,
    .toolbar > .report-tabs,
    .toolbar > .alert,
    .toolbar > .filters,
    .toolbar > .summary { display: none !important; }
    .report-card-wrapper { border: none; padding: 0; max-width: 100%; margin: 0 0 0; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/../preloader.php'; ?>
<div class="toolbar">
    <div class="header">
        <h1>Bulk Print Reports</h1>
        <a href="<?= $is_teacher ? '../teachers_portal.php' : '../school_admin/school_admin_dashboard.php' ?>" class="btn-link">&larr; Back</a>
    </div>

    <?php if (($_SESSION['role'] ?? '') === 'school_admin'): ?>
        <div class="report-tabs">
            <a href="grading_scales.php">Grading &amp; Bands</a>
            <a href="report_settings.php">Display Settings</a>
            <a href="bulk_report_print.php" class="active">Print Reports</a>
            <a href="remarks.php">Remarks</a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

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
        <?php if (!empty($reports)): ?>
            <div class="summary">
                <?= count($reports) ?> report card(s) loaded for <strong><?= htmlspecialchars($class_label) ?></strong>, <?= htmlspecialchars($term) ?> <?= $year ?>. Click a student on the left to preview their report.
            </div>

            <div class="report-layout">
                <aside class="roster-col">
                    <input type="text" class="roster-search" id="rosterSearch" placeholder="Filter by name..." oninput="scholarFilterRoster(this.value)">
                    <div class="roster-list" id="rosterStrip">
                        <?php foreach ($reports as $i => $r): ?>
                            <button type="button"
                                    data-name="<?= htmlspecialchars(strtolower($r['name']), ENT_QUOTES) ?>"
                                    class="<?= $i === 0 ? 'active' : '' ?>"
                                    onclick="scholarShowStudent(<?= (int) $r['id'] ?>, this)">
                                <?= htmlspecialchars($r['name']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </aside>

                <div class="preview-col">
                    <div class="preview-actions">
                        <button type="button" onclick="window.print();">Print This Student</button>
                        <button type="button" onclick="scholarPrintAll();">Print Whole Class</button>
                    </div>

                    <?php foreach ($reports as $i => $r): ?>
                        <div class="report-panel <?= $i === 0 ? 'active' : '' ?>" data-student="<?= (int) $r['id'] ?>">
                            <?= $r['html'] ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <p class="empty">No students found in this class.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="../assets/js/qrcode.js"></script>
<script>
document.querySelectorAll('.rc-qr-target').forEach(function (el) {
    var q = qrcode(0, 'M');
    q.addData(el.getAttribute('data-qr'));
    q.make();
    el.innerHTML = q.createSvgTag(4, 0);
});
</script>
<script>
function scholarShowStudent(studentId, btn) {
    document.querySelectorAll('.report-panel').forEach(function (panel) {
        panel.classList.toggle('active', panel.getAttribute('data-student') === String(studentId));
    });
    document.querySelectorAll('#rosterStrip button').forEach(function (b) {
        b.classList.toggle('active', b === btn);
    });
}

function scholarFilterRoster(query) {
    var q = query.trim().toLowerCase();
    document.querySelectorAll('#rosterStrip button').forEach(function (b) {
        b.classList.toggle('hidden-by-search', q !== '' && b.getAttribute('data-name').indexOf(q) === -1);
    });
}

function scholarPrintAll() {
    document.body.classList.add('print-all');
    window.print();
}
window.addEventListener('afterprint', function () {
    document.body.classList.remove('print-all');
});
</script>

</body>
</html>

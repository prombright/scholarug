<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER: MARKS ENTRY
|--------------------------------------------------------------------------
| Extracted from the old teachers_portal.php, which used to BE this tool
| directly. teachers_portal.php is now a card-grid landing page instead
| (see _teacher_shell.php's header comment) -- all the marks-entry logic
| and UI below is unchanged, just moved onto its own page behind the
| shared sidebar.
|--------------------------------------------------------------------------
*/

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}

$school_id = $_SESSION['school_id'];
$staff_id  = $_SESSION['staff_id']; // Direct reference to staff profile
$message   = "";

// --- DOWNLOAD A MARKS-ENTRY TEMPLATE (must run before any HTML output) ---
// Pre-filled with the current roster (names + any marks already on file)
// so a teacher can fill it in offline and re-upload via the import below,
// rather than typing into the web form row by row. Same header-then-exit
// pattern as school_admin/students.php's CSV template download.
if (isset($_GET['download_marks_template']) && $_GET['download_marks_template'] === 'csv') {
    $tpl_class_id      = intval($_GET['class_id'] ?? 0);
    $tpl_subject_id    = intval($_GET['subject_id'] ?? 0);
    $tpl_assessment_id = intval($_GET['assessment_id'] ?? 0);
    $tpl_paper_number  = intval($_GET['paper_number'] ?? 1);

    $tpl_verify = $pdo->prepare("
        SELECT id FROM teacher_assignments
        WHERE school_id = ? AND teacher_id = ? AND class_id = ? AND subject_id = ? AND paper_number = ?
    ");
    $tpl_verify->execute([$school_id, $staff_id, $tpl_class_id, $tpl_subject_id, $tpl_paper_number]);

    if (!$tpl_verify->fetch()) {
        http_response_code(403);
        exit('You are not assigned to this class/subject/paper.');
    }

    // Elective subjects only roster students actually enrolled in them
    // (via student_subjects); Core subjects keep today's whole-class list.
    $tpl_type_stmt = $pdo->prepare("SELECT subject_type FROM subjects WHERE id = ? AND school_id = ?");
    $tpl_type_stmt->execute([$tpl_subject_id, $school_id]);
    $tpl_is_elective = $tpl_type_stmt->fetchColumn() === 'Elective';

    $tpl_students = $pdo->prepare("
        SELECT st.id, st.student_no, st.full_name, sm.marks
        FROM students st
        LEFT JOIN student_marks sm
               ON st.id = sm.student_id
              AND sm.subject_id = :subject_id
              AND sm.assessment_id = :assessment_id
              AND sm.paper_number = :paper_number
        WHERE st.school_id = :school_id AND st.class_id = :class_id
        " . ($tpl_is_elective ? 'AND EXISTS (SELECT 1 FROM student_subjects ss WHERE ss.student_id = st.id AND ss.subject_id = :elective_subject_id)' : '') . "
        ORDER BY st.full_name ASC
    ");
    $tpl_params = [
        ':school_id'     => $school_id,
        ':class_id'      => $tpl_class_id,
        ':subject_id'    => $tpl_subject_id,
        ':assessment_id' => $tpl_assessment_id,
        ':paper_number'  => $tpl_paper_number,
    ];
    if ($tpl_is_elective) {
        $tpl_params[':elective_subject_id'] = $tpl_subject_id;
    }
    $tpl_students->execute($tpl_params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=marks_entry_template.csv');

    // Student ID (this app's own internal id, always present) is the real
    // match key on re-upload -- Student No is shown for the teacher's own
    // reference only. Most students at real schools never get a Student No
    // assigned (confirmed live: 442 of 466 non-graduated students have none
    // set), so matching on it alone silently dropped nearly every imported
    // mark -- the roster below never looked "populated" after import
    // because most rows never actually made it into student_marks.
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Student No', 'Full Name', 'Marks (0-100)']);
    foreach ($tpl_students as $row) {
        fputcsv($out, [$row['id'], $row['student_no'] ?? '', $row['full_name'], $row['marks'] ?? '']);
    }
    fclose($out);
    exit();
}

// --- IMPORT MARKS FROM A FILLED-IN TEMPLATE ---
// Matched by Student No against this class's roster, written through the
// exact same upsert as a manual Save -- always lands as a draft, never
// auto-submitted, so a teacher reviews on-screen before Submitting.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_marks_csv']) && isset($_FILES['marks_csv'])) {
    $imp_class_id      = intval($_POST['class_id']);
    $imp_subject_id    = intval($_POST['subject_id']);
    $imp_assessment_id = intval($_POST['assessment_id']);
    $imp_paper_number  = intval($_POST['paper_number'] ?? 1);

    $imp_verify = $pdo->prepare("
        SELECT id FROM teacher_assignments
        WHERE school_id = ? AND teacher_id = ? AND class_id = ? AND subject_id = ? AND paper_number = ?
    ");
    $imp_verify->execute([$school_id, $staff_id, $imp_class_id, $imp_subject_id, $imp_paper_number]);

    // 'Closed' used to only be a UI hint (hidden from the assessment
    // dropdown below) -- a direct POST could still write marks against a
    // closed assessment. Close Term relies on this actually being
    // enforced, not just hidden.
    $imp_status_stmt = $pdo->prepare("SELECT status FROM assessments WHERE id = ? AND school_id = ?");
    $imp_status_stmt->execute([$imp_assessment_id, $school_id]);
    $imp_assessment_status = $imp_status_stmt->fetchColumn();

    if (!$imp_verify->fetch()) {
        $message = "<div class='alert alert-danger'>Unauthorized import: You are not assigned to this class/subject/paper.</div>";
    } elseif ($imp_assessment_status !== 'Open') {
        $message = "<div class='alert alert-danger'>This assessment is closed and no longer accepting marks.</div>";
    } elseif (empty($_FILES['marks_csv']['tmp_name']) || !is_uploaded_file($_FILES['marks_csv']['tmp_name'])) {
        $message = "<div class='alert alert-danger'>Please choose a CSV file to upload.</div>";
    } else {
        $roster_by_id_stmt  = $pdo->prepare("SELECT id FROM students WHERE school_id = ? AND class_id = ? AND id = ?");
        $roster_by_no_stmt  = $pdo->prepare("SELECT id FROM students WHERE school_id = ? AND class_id = ? AND student_no = ?");

        $handle = fopen($_FILES['marks_csv']['tmp_name'], 'r');
        $header = fgetcsv($handle, 1000, ',');

        // Match column positions by name instead of a hardcoded index, so
        // a template downloaded before Student ID existed (Student No,
        // Full Name, Marks) still imports correctly -- it just falls back
        // to matching by Student No for that older format.
        $col = array_flip(array_map('trim', $header ?: []));
        $idx_id    = $col['Student ID'] ?? null;
        $idx_no    = $col['Student No'] ?? 0;
        $idx_marks = $col['Marks (0-100)'] ?? (count($col) - 1);

        $ins = $pdo->prepare("
            INSERT INTO student_marks (school_id, student_id, class_id, subject_id, paper_number, teacher_id, assessment_id, marks, submission_status, submitted_at)
            VALUES (:school_id, :student_id, :class_id, :subject_id, :paper_number, :teacher_id, :assessment_id, :marks, 'draft', NULL)
            ON DUPLICATE KEY UPDATE
                marks = VALUES(marks),
                teacher_id = VALUES(teacher_id),
                submission_status = 'draft',
                submitted_at = NULL,
                updated_at = NOW()
        ");

        $imported = 0;
        $skipped = 0;
        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $csv_student_id = $idx_id !== null ? trim($row[$idx_id] ?? '') : '';
            $csv_student_no = trim($row[$idx_no] ?? '');
            $csv_mark       = trim($row[$idx_marks] ?? '');

            if (($csv_student_id === '' && $csv_student_no === '') || $csv_mark === '' || !is_numeric($csv_mark)) {
                if ($csv_student_id !== '' || $csv_student_no !== '') $skipped++;
                continue;
            }

            // Same 0-100 guard as the manual entry form -- an imported
            // CSV is just as capable of carrying a stray typo'd mark.
            if ((float) $csv_mark < 0 || (float) $csv_mark > 100) {
                $skipped++;
                continue;
            }

            // Student ID (this app's own id, on every current template) is
            // the real match key -- falls back to Student No only for a
            // template downloaded before Student ID existed. Matching on
            // Student No alone used to silently drop nearly every row: most
            // real students never get one assigned.
            $matched_student_id = null;
            if ($csv_student_id !== '' && ctype_digit($csv_student_id)) {
                $roster_by_id_stmt->execute([$school_id, $imp_class_id, (int) $csv_student_id]);
                $matched_student_id = $roster_by_id_stmt->fetchColumn();
            }
            if (!$matched_student_id && $csv_student_no !== '') {
                $roster_by_no_stmt->execute([$school_id, $imp_class_id, $csv_student_no]);
                $matched_student_id = $roster_by_no_stmt->fetchColumn();
            }

            if (!$matched_student_id) {
                $skipped++;
                continue;
            }

            $ins->execute([
                ':school_id'     => $school_id,
                ':student_id'    => (int) $matched_student_id,
                ':class_id'      => $imp_class_id,
                ':subject_id'    => $imp_subject_id,
                ':paper_number'  => $imp_paper_number,
                ':teacher_id'    => $staff_id,
                ':assessment_id' => $imp_assessment_id,
                ':marks'         => (float) $csv_mark,
            ]);
            $imported++;
        }
        fclose($handle);

        $message = "<div class='alert alert-success'>Imported {$imported} mark(s) as draft — review below, then Submit when ready.</div>"
            . ($skipped > 0 ? "<div class='alert alert-danger'>{$skipped} row(s) skipped (blank/out-of-range mark or Student No not found in this class).</div>" : '');
    }
}

// --- HANDLE MARKS SUBMISSION ---
// Two distinct actions from one form: Save keeps marks editable (draft,
// excluded from the report card); Submit finalizes them (counted on the
// report). See _setup/marks_submission_workflow.sql for the schema.
$posted_action = isset($_POST['submit_marks']) ? 'submitted' : (isset($_POST['save_marks']) ? 'draft' : null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $posted_action !== null) {
    $class_id      = intval($_POST['class_id']);
    $subject_id    = intval($_POST['subject_id']);
    $assessment_id = intval($_POST['assessment_id']);
    $paper_number  = intval($_POST['paper_number'] ?? 1);
    $marks_input   = $_POST['marks'] ?? [];

    // Verify teacher is actually assigned to this class/subject/paper before saving
    $verify_stmt = $pdo->prepare("
        SELECT id FROM teacher_assignments
        WHERE school_id = ? AND teacher_id = ? AND class_id = ? AND subject_id = ? AND paper_number = ?
    ");
    $verify_stmt->execute([$school_id, $staff_id, $class_id, $subject_id, $paper_number]);

    // Same enforcement as the CSV import path above -- 'Closed' must
    // actually block writes, not just be hidden from the dropdown.
    $status_stmt = $pdo->prepare("SELECT status FROM assessments WHERE id = ? AND school_id = ?");
    $status_stmt->execute([$assessment_id, $school_id]);
    $assessment_status = $status_stmt->fetchColumn();

    if ($assessment_status !== 'Open') {
        $message = "<div class='alert alert-danger'>This assessment is closed and no longer accepting marks.</div>";
    } elseif ($verify_stmt->fetch()) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO student_marks (school_id, student_id, class_id, subject_id, paper_number, teacher_id, assessment_id, marks, submission_status, submitted_at)
                VALUES (:school_id, :student_id, :class_id, :subject_id, :paper_number, :teacher_id, :assessment_id, :marks, :status, :submitted_at)
                ON DUPLICATE KEY UPDATE
                    marks = VALUES(marks),
                    teacher_id = VALUES(teacher_id),
                    submission_status = VALUES(submission_status),
                    submitted_at = VALUES(submitted_at),
                    updated_at = NOW()
            ");

            $submitted_at = $posted_action === 'submitted' ? date('Y-m-d H:i:s') : null;
            $touched = 0;
            $out_of_range = 0;
            foreach ($marks_input as $student_id => $mark_val) {
                if ($mark_val === '' || $mark_val === null) continue;
                // The form's inputs have min="0" max="100", browser-side
                // only -- a direct POST could otherwise write a mark
                // outside that range straight onto a report card with no
                // error shown. Skip it rather than clamp/guess.
                $mark_val = floatval($mark_val);
                if ($mark_val < 0 || $mark_val > 100) {
                    $out_of_range++;
                    continue;
                }
                $stmt->execute([
                    ':school_id'     => $school_id,
                    ':student_id'    => intval($student_id),
                    ':class_id'      => $class_id,
                    ':subject_id'    => $subject_id,
                    ':paper_number'  => $paper_number,
                    ':teacher_id'    => $staff_id,
                    ':assessment_id' => $assessment_id,
                    ':marks'         => $mark_val,
                    ':status'        => $posted_action,
                    ':submitted_at'  => $submitted_at,
                ]);
                $touched++;
            }
            $pdo->commit();

            $out_of_range_notice = $out_of_range > 0
                ? "<div class='alert alert-warning'>$out_of_range mark(s) outside the 0-100 range were skipped and not saved.</div>"
                : '';

            $message = $out_of_range_notice . ($posted_action === 'submitted'
                ? "<div class='alert alert-success'>{$touched} mark(s) submitted — now counted on the report card.</div>"
                : "<div class='alert alert-success'>{$touched} mark(s) saved as draft — not yet on the report. Submit when ready.</div>");
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='alert alert-danger'>Error saving marks: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Unauthorized submission: You are not assigned to this class/subject/paper.</div>";
    }
}

// --- FETCH ONLY ASSIGNED CLASSES & SUBJECTS FOR THIS TEACHER ---
$assigned_stmt = $pdo->prepare("
    SELECT DISTINCT
        ta.class_id, c.class_name,
        ta.subject_id, s.subject_name, s.subject_code,
        ta.paper_number, s.papers_count
    FROM teacher_assignments ta
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    WHERE ta.school_id = :school_id AND ta.teacher_id = :staff_id
");
$assigned_stmt->execute([':school_id' => $school_id, ':staff_id' => $staff_id]);
$my_assignments = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

// --- FETCH ADMIN-SET OPEN ASSESSMENTS ---
$assessments_stmt = $pdo->prepare("
    SELECT id, title, weight_percentage, term, year
    FROM assessments
    WHERE school_id = ? AND status = 'Open'
    ORDER BY id DESC
");
$assessments_stmt->execute([$school_id]);
$active_assessments = $assessments_stmt->fetchAll(PDO::FETCH_ASSOC);

// --- THREE-STEP FLOW: pick an assessment, then pick one of YOUR classes,
// then enter marks. Replaces the old single-page "both dropdowns at once"
// form -- landing on this page with no assessment chosen now shows the
// pending assessments themselves instead of an empty picker. ---
$sel_assessment = isset($_GET['assessment_id']) ? (int) $_GET['assessment_id'] : 0;

// Only an assessment id that's actually Open and belongs to this school
// counts -- a stale/guessed id just falls back to the assessment-picker
// view instead of silently accepting it.
$assessment = null;
foreach ($active_assessments as $a) {
    if ((int) $a['id'] === $sel_assessment) {
        $assessment = $a;
        break;
    }
}
if (!$assessment) {
    $sel_assessment = 0;
}

// The class-picker step below links straight to
// ?class_id=X&subject_id=Y&paper_number=Z (plain hrefs, no JS involved),
// so these normally arrive directly. class_subject is accepted too as a
// defensive fallback for any old bookmarked/combined-value link.
$sel_class   = $_GET['class_id'] ?? null;
$sel_subject = $_GET['subject_id'] ?? null;
$sel_paper   = intval($_GET['paper_number'] ?? 1);
if ((!$sel_class || !$sel_subject) && !empty($_GET['class_subject'])) {
    $parts = explode('|', (string) $_GET['class_subject']);
    $sel_class   = $sel_class ?: ($parts[0] ?? null);
    $sel_subject = $sel_subject ?: ($parts[1] ?? null);
    $sel_paper   = $sel_paper ?: (int) ($parts[2] ?? 1);
}

// "Only classes they teach" enforced here too, not just at save time --
// this is a read (viewing a roster), so it deserves the same ownership
// check as the write path below, not just a trust-the-link assumption.
$sel_assignment = null;
if ($sel_class && $sel_subject) {
    foreach ($my_assignments as $assign) {
        if ((int) $assign['class_id'] === (int) $sel_class
            && (int) $assign['subject_id'] === (int) $sel_subject
            && (int) $assign['paper_number'] === $sel_paper
        ) {
            $sel_assignment = $assign;
            break;
        }
    }
    if (!$sel_assignment) {
        $sel_class = null;
        $sel_subject = null;
    }
}

$students_list = [];

if ($sel_assessment && $sel_class && $sel_subject) {
    // Elective subjects only roster students actually enrolled in them
    // (via student_subjects); Core subjects keep today's whole-class list.
    $sel_type_stmt = $pdo->prepare("SELECT subject_type FROM subjects WHERE id = ? AND school_id = ?");
    $sel_type_stmt->execute([$sel_subject, $school_id]);
    $sel_is_elective = $sel_type_stmt->fetchColumn() === 'Elective';

    $students_stmt = $pdo->prepare("
        SELECT st.id AS student_id, st.full_name, st.student_no, sm.marks, sm.submission_status
        FROM students st
        LEFT JOIN student_marks sm
               ON st.id = sm.student_id
              AND sm.subject_id = :subject_id
              AND sm.assessment_id = :assessment_id
              AND sm.paper_number = :paper_number
        WHERE st.school_id = :school_id AND st.class_id = :class_id
        " . ($sel_is_elective ? 'AND EXISTS (SELECT 1 FROM student_subjects ss WHERE ss.student_id = st.id AND ss.subject_id = :elective_subject_id)' : '') . "
        ORDER BY st.full_name ASC
    ");
    $students_params = [
        ':school_id'     => $school_id,
        ':class_id'      => $sel_class,
        ':subject_id'    => $sel_subject,
        ':assessment_id' => $sel_assessment,
        ':paper_number'  => $sel_paper,
    ];
    if ($sel_is_elective) {
        $students_params[':elective_subject_id'] = $sel_subject;
    }
    $students_stmt->execute($students_params);
    $students_list = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Per-assessment progress teaser for the picker view: how many of this
// teacher's own assignments have at least one mark on file for that
// assessment yet. One grouped query instead of one per assessment.
$progress_by_assessment = [];
if (!$sel_assessment && !empty($active_assessments) && !empty($my_assignments)) {
    $progress_stmt = $pdo->prepare("
        SELECT assessment_id, class_id, subject_id, paper_number
        FROM student_marks
        WHERE school_id = ? AND teacher_id = ?
        GROUP BY assessment_id, class_id, subject_id, paper_number
    ");
    $progress_stmt->execute([$school_id, $staff_id]);
    $started = [];
    foreach ($progress_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $started[$row['assessment_id'] . ':' . $row['class_id'] . ':' . $row['subject_id'] . ':' . $row['paper_number']] = true;
    }
    foreach ($active_assessments as $a) {
        $doneCount = 0;
        foreach ($my_assignments as $assign) {
            $key = $a['id'] . ':' . $assign['class_id'] . ':' . $assign['subject_id'] . ':' . $assign['paper_number'];
            if (isset($started[$key])) {
                $doneCount++;
            }
        }
        $progress_by_assessment[$a['id']] = ['done' => $doneCount, 'total' => count($my_assignments)];
    }
}

// Is this teacher the class teacher of anything? (drives the Phase 3 bulk-print link)
$class_teacher_stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND class_teacher_id = ?");
$class_teacher_stmt->execute([$school_id, $staff_id]);
$is_any_class_teacher = (int) $class_teacher_stmt->fetchColumn() > 0;

// Unread student messages across every conversation this teacher is in —
// drives the badge on the "Messages" nav link in the sidebar.
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

$ACTIVE_NAV = 'marks';
require_once __DIR__ . '/_teacher_shell.php';
?>
<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.filters{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:24px;}
.filters .row{display:grid;grid-template-columns:2fr 2fr 1fr;gap:14px;align-items:end;}
@media(max-width:768px){ .filters .row{grid-template-columns:1fr;} }
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select,input[type=number]{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;}
select:focus,input[type=number]:focus{outline:none;border-color:var(--cyan);}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;}
.btn-primary{background:var(--cyan);color:#04121a;}
.btn-ghost{background:transparent;color:var(--text);border:1px solid var(--border);}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);font-size:0.9rem;font-weight:700;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
.stat-summary{color:var(--muted);font-size:0.78rem;font-weight:500;text-transform:none;letter-spacing:0;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:12px 20px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;background:rgba(255,255,255,0.02);}
tbody tr:last-child td{border-bottom:none;}
.section-footer{padding:16px 20px;text-align:right;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.pill{display:inline-block;font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;}
.pill.submitted{background:rgba(16,185,129,0.12);color:var(--green);}
.pill.draft{background:rgba(245,158,11,0.14);color:var(--amber);}
.pill.empty-pill{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.dirty{background:rgba(0,168,168,0.15);color:var(--cyan);}
table{display:block;overflow-x:auto;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;}
.pick-card{display:block;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;text-decoration:none;color:var(--text);transition:border-color .15s,transform .15s;}
.pick-card:hover{border-color:var(--cyan);transform:translateY(-2px);}
.pick-card h3{margin:0 0 6px;font-size:1rem;}
.pick-card .sub{color:var(--muted);font-size:0.78rem;margin-bottom:10px;}
.pick-card .progress-bar{background:var(--border);border-radius:6px;overflow:hidden;height:6px;margin-bottom:6px;}
.pick-card .progress-fill{height:100%;background:var(--cyan);}
.pick-card .progress-label{font-size:0.72rem;color:var(--muted);}
.breadcrumb{color:var(--muted);font-size:0.82rem;margin-bottom:18px;}
.breadcrumb a{color:var(--cyan);text-decoration:none;}
</style>
<div class="page-title">Assessment Marks Entry</div>

<?= $message ?>

<?php if (!$sel_assessment): ?>

    <?php if (empty($active_assessments)): ?>
        <p class="empty">No assessments are currently open for marks entry. Check back once your school admin opens one.</p>
    <?php elseif (empty($my_assignments)): ?>
        <p class="empty">You have no class/subject assignments yet — ask your school admin to assign you via Teacher Assignments.</p>
    <?php else: ?>
        <p class="page-sub" style="color:var(--muted);font-size:0.85rem;margin:-10px 0 18px;">Choose an assessment to enter marks for.</p>
        <div class="card-grid">
            <?php foreach ($active_assessments as $a): ?>
                <?php $prog = $progress_by_assessment[$a['id']] ?? ['done' => 0, 'total' => count($my_assignments)]; ?>
                <a class="pick-card" href="?assessment_id=<?= (int) $a['id'] ?>">
                    <h3><?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <div class="sub"><?= htmlspecialchars($a['term'] . ' ' . $a['year'] . ' · ' . $a['weight_percentage'] . '% weight', ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= $prog['total'] > 0 ? round($prog['done'] / $prog['total'] * 100) : 0 ?>%;"></div></div>
                    <div class="progress-label"><?= $prog['done'] ?> of <?= $prog['total'] ?> of your classes started</div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif (!$sel_class || !$sel_subject): ?>

    <div class="breadcrumb"><a href="?">All Assessments</a> &rsaquo; <?= htmlspecialchars($assessment['title'], ENT_QUOTES, 'UTF-8') ?></div>
    <p class="page-sub" style="color:var(--muted);font-size:0.85rem;margin:-10px 0 18px;">Choose which of your classes to enter marks for.</p>
    <div class="card-grid">
        <?php foreach ($my_assignments as $assign): ?>
            <a class="pick-card" href="?assessment_id=<?= (int) $sel_assessment ?>&class_id=<?= (int) $assign['class_id'] ?>&subject_id=<?= (int) $assign['subject_id'] ?>&paper_number=<?= (int) $assign['paper_number'] ?>">
                <h3><?= htmlspecialchars($assign['class_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="sub">
                    <?= htmlspecialchars($assign['subject_name'] . ' (' . $assign['subject_code'] . ')', ENT_QUOTES, 'UTF-8') ?>
                    <?= (int) $assign['papers_count'] > 1 ? ' — Paper ' . (int) $assign['paper_number'] : '' ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

<?php else: ?>

    <div class="breadcrumb">
        <a href="?">All Assessments</a> &rsaquo;
        <a href="?assessment_id=<?= (int) $sel_assessment ?>"><?= htmlspecialchars($assessment['title'], ENT_QUOTES, 'UTF-8') ?></a> &rsaquo;
        <?= htmlspecialchars($sel_assignment['class_name'] . ' — ' . $sel_assignment['subject_name'], ENT_QUOTES, 'UTF-8') ?>
    </div>

    <?php if (!empty($students_list)): ?>
    <div class="filters" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;">
        <a class="btn-link"
           href="?download_marks_template=csv&class_id=<?= urlencode($sel_class) ?>&subject_id=<?= urlencode($sel_subject) ?>&assessment_id=<?= urlencode((string) $sel_assessment) ?>&paper_number=<?= urlencode((string) $sel_paper) ?>">
            Download Marks Template
        </a>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;">
            <input type="hidden" name="class_id" value="<?= htmlspecialchars($sel_class) ?>">
            <input type="hidden" name="subject_id" value="<?= htmlspecialchars($sel_subject) ?>">
            <input type="hidden" name="assessment_id" value="<?= htmlspecialchars((string) $sel_assessment) ?>">
            <input type="hidden" name="paper_number" value="<?= htmlspecialchars((string) $sel_paper) ?>">
            <input type="file" name="marks_csv" accept=".csv" required style="width:auto;">
            <button type="submit" name="import_marks_csv" value="1" class="btn-ghost">Import CSV</button>
        </form>
    </div>

    <div id="rosterApp">
        <form method="POST">
            <input type="hidden" name="class_id" value="<?= htmlspecialchars($sel_class) ?>">
            <input type="hidden" name="subject_id" value="<?= htmlspecialchars($sel_subject) ?>">
            <input type="hidden" name="assessment_id" value="<?= htmlspecialchars((string) $sel_assessment) ?>">
            <input type="hidden" name="paper_number" value="<?= htmlspecialchars((string) $sel_paper) ?>">

            <div class="section">
                <div class="section-header">
                    <span>Student Roster Entry<?= $sel_paper > 1 ? ' — Paper ' . (int) $sel_paper : '' ?></span>
                    <span class="stat-summary">{{ submittedCount }} submitted · {{ draftCount }} draft · {{ emptyCount }} not entered</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student No.</th>
                            <th>Full Name</th>
                            <th style="width:160px;">Raw Mark (100%)</th>
                            <th style="width:110px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in students" :key="row.student_id">
                            <td>{{ i + 1 }}</td>
                            <td style="color:var(--muted);">{{ row.student_no || 'N/A' }}</td>
                            <td>{{ row.full_name }}</td>
                            <td>
                                <input type="number" step="0.1" min="0" max="100"
                                       :name="'marks[' + row.student_id + ']'"
                                       v-model="row.marks"
                                       @input="row.dirty = true"
                                       placeholder="0 - 100">
                            </td>
                            <td>
                                <span v-if="row.dirty" class="pill dirty">Unsaved</span>
                                <span v-else-if="row.submission_status === 'submitted'" class="pill submitted">Submitted</span>
                                <span v-else-if="row.submission_status === 'draft'" class="pill draft">Draft</span>
                                <span v-else class="pill empty-pill">Not entered</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="section-footer">
                    <button type="submit" name="save_marks" class="btn-ghost">Save as Draft</button>
                    <button type="submit" name="submit_marks" class="btn-primary">Submit to Report</button>
                </div>
            </div>
        </form>
    </div>
    <?php else: ?>
        <p class="empty">No students found in this class.</p>
    <?php endif; ?>

<?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
<script>
<?php if (!empty($students_list)): ?>
Vue.createApp({
    data() {
        return {
            students: <?= json_encode(array_map(function ($s) {
                $s['dirty'] = false;
                return $s;
            }, $students_list), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        };
    },
    computed: {
        submittedCount() { return this.students.filter(s => !s.dirty && s.submission_status === 'submitted').length; },
        draftCount() { return this.students.filter(s => !s.dirty && s.submission_status === 'draft').length; },
        emptyCount() { return this.students.filter(s => !s.dirty && !s.submission_status).length; },
    },
}).mount('#rosterApp');
<?php endif; ?>
</script>
</body>
</html>

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER SUBMISSIONS (admin oversight)
|--------------------------------------------------------------------------
| Which teachers have/haven't submitted marks for a given assessment.
| student_marks.submission_status already exists and is already read/
| written by teacher_marks_entry.php -- this is the first page that
| surfaces it to an admin. Driven from teacher_assignments (not just
| student_marks) so a teacher who hasn't entered ANY marks yet still shows
| up as "Not Started" instead of being invisible.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';

require_role(['school_admin']);

$school_id = current_school_id();

$assessments_stmt = $pdo->prepare("SELECT id, title, term, year FROM assessments WHERE school_id = ? ORDER BY year DESC, id DESC");
$assessments_stmt->execute([$school_id]);
$assessments = $assessments_stmt->fetchAll(PDO::FETCH_ASSOC);

$classes_stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE school_id = ? ORDER BY class_name");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$sel_assessment = isset($_GET['assessment_id']) && $_GET['assessment_id'] !== '' ? (int) $_GET['assessment_id'] : null;
$sel_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;

$rows = [];
if ($sel_assessment !== null) {
    $allowed_assessment_ids = array_map('intval', array_column($assessments, 'id'));
    if (!in_array($sel_assessment, $allowed_assessment_ids, true)) {
        $sel_assessment = null;
    } else {
        $sql = "
            SELECT
                ta.teacher_id, ta.class_id, ta.subject_id,
                c.class_name, sub.subject_name,
                TRIM(CONCAT(st.first_name, ' ', st.last_name)) AS teacher_name,
                COUNT(CASE WHEN sm.submission_status = 'submitted' THEN 1 END) AS submitted_count,
                COUNT(CASE WHEN sm.submission_status = 'draft' THEN 1 END) AS draft_count
            FROM teacher_assignments ta
            JOIN classes c ON c.id = ta.class_id
            JOIN subjects sub ON sub.id = ta.subject_id
            JOIN staff st ON st.staff_id = ta.teacher_id AND st.school_id = ta.school_id
            LEFT JOIN student_marks sm ON sm.class_id = ta.class_id AND sm.subject_id = ta.subject_id
                AND sm.teacher_id = ta.teacher_id AND sm.assessment_id = ?
            WHERE ta.school_id = ?
        ";
        $params = [$sel_assessment, $school_id];
        if ($sel_class !== null) {
            $sql .= " AND ta.class_id = ?";
            $params[] = $sel_class;
        }
        $sql .= " GROUP BY ta.class_id, ta.subject_id, ta.teacher_id ORDER BY c.class_name, sub.subject_name, teacher_name";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$SCHOLAR_BASE = '../';
$ACTIVE_NAV = 'teacher_submissions';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.filter-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;}
.filter-bar div{min-width:200px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select{width:100%;padding:9px 10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
button{background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.pill-none{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill-progress{background:rgba(245,158,11,0.14);color:#f59e0b;}
.pill-done{background:rgba(16,185,129,0.12);color:var(--green);}
.empty{color:var(--muted);font-size:0.85rem;padding:20px;text-align:center;}
</style>
<main class="main-content">
<div class="page-inner">
    <h1 style="font-size:1.4rem;">Teacher Submissions</h1>

    <div class="section">
        <form method="GET" class="filter-bar">
            <div>
                <label>Assessment</label>
                <select name="assessment_id" required>
                    <option value="">-- Select Assessment --</option>
                    <?php foreach ($assessments as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= $sel_assessment === (int) $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['title']) ?> (<?= htmlspecialchars($a['term']) ?> <?= (int) $a['year'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Class (optional)</label>
                <select name="class_id">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $sel_class === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button type="submit">Filter</button></div>
        </form>
    </div>

    <?php if ($sel_assessment === null): ?>
        <p class="empty">Pick an assessment above to see submission status per teacher.</p>
    <?php else: ?>
        <div class="section" style="padding:0;">
            <table>
                <tr><th>Teacher</th><th>Class</th><th>Subject</th><th>Submitted</th><th>Draft</th><th>Status</th></tr>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="empty">No teacher assignments found for this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $submitted = (int) $r['submitted_count'];
                        $draft = (int) $r['draft_count'];
                        if ($submitted === 0 && $draft === 0) { $pillClass = 'pill-none'; $pillLabel = 'Not Started'; }
                        elseif ($draft > 0) { $pillClass = 'pill-progress'; $pillLabel = 'In Progress'; }
                        else { $pillClass = 'pill-done'; $pillLabel = 'Submitted'; }
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($r['teacher_name']) ?></td>
                            <td><?= htmlspecialchars($r['class_name']) ?></td>
                            <td><?= htmlspecialchars($r['subject_name']) ?></td>
                            <td><?= $submitted ?></td>
                            <td><?= $draft ?></td>
                            <td><span class="pill <?= $pillClass ?>"><?= $pillLabel ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>
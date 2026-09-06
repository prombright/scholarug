<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — GENERIC SKILLS ENTRY (class teacher only)
|--------------------------------------------------------------------------
| A class teacher rates each active generic_skills row per student in
| their own class, using the school's own grading_scales vocabulary
| (same dropdown values as subject grades -- one vocabulary, not two).
| Gated by is_class_teacher_of(), same as bulk_report_print.php's
| per-class scoping.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['teacher']);

$school_id = current_school_id();
$staff_id = current_staff_id();
$message = '';

$classesStmt = $pdo->prepare("SELECT id, class_name, stream_name FROM classes WHERE school_id = ? AND class_teacher_id = ? ORDER BY class_name");
$classesStmt->execute([$school_id, $staff_id]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

$sel_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;
$term = $_GET['term'] ?? current_term();
$year = (int) ($_GET['year'] ?? current_year());

if ($sel_class !== null && !is_class_teacher_of($pdo, $staff_id, $sel_class)) {
    $sel_class = null;
    $message = "<div class='alert alert-danger'>You are not the class teacher of that class.</div>";
}

// ---- Save ratings ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ratings'])) {
    $post_class_id = (int) ($_POST['class_id'] ?? 0);
    $post_term = trim($_POST['term'] ?? '');
    $post_year = (int) ($_POST['year'] ?? 0);
    $ratings = $_POST['rating'] ?? []; // [student_id][skill_id] => grade

    if (!is_class_teacher_of($pdo, $staff_id, $post_class_id)) {
        $message = "<div class='alert alert-danger'>You are not the class teacher of that class.</div>";
    } else {
        $upsert = $pdo->prepare("
            INSERT INTO student_skill_ratings (school_id, student_id, skill_id, term, year, rating_grade, rated_by)
            VALUES (:school_id, :student_id, :skill_id, :term, :year, :grade, :rated_by)
            ON DUPLICATE KEY UPDATE rating_grade = VALUES(rating_grade), rated_by = VALUES(rated_by), updated_at = NOW()
        ");
        $touched = 0;
        foreach ($ratings as $student_id => $skill_grades) {
            foreach ($skill_grades as $skill_id => $grade) {
                $grade = trim((string) $grade);
                if ($grade === '') continue;
                $upsert->execute([
                    ':school_id'  => $school_id,
                    ':student_id' => (int) $student_id,
                    ':skill_id'   => (int) $skill_id,
                    ':term'       => $post_term,
                    ':year'       => $post_year,
                    ':grade'      => $grade,
                    ':rated_by'   => $staff_id,
                ]);
                $touched++;
            }
        }
        $message = "<div class='alert alert-success'>{$touched} rating(s) saved.</div>";
        $sel_class = $post_class_id;
        $term = $post_term;
        $year = $post_year;
    }
}

$skills = $pdo->prepare("SELECT id, skill_name FROM generic_skills WHERE school_id = ? AND is_active = 1 ORDER BY display_order, skill_name");
$skills->execute([$school_id]);
$skills = $skills->fetchAll(PDO::FETCH_ASSOC);

// Generic skills are a lower-secondary (O-Level/CBC) feature -- A-Level's
// UACE letter scale (A-F, with a real fail grade) isn't a sensible rating
// vocabulary for a "Cooperation" or "Creativity" skill.
$grade_options = $pdo->prepare("SELECT DISTINCT grade FROM grading_scales WHERE school_id = ? AND level_type = 'O-Level' ORDER BY min_mark DESC");
$grade_options->execute([$school_id]);
$grade_options = array_column($grade_options->fetchAll(PDO::FETCH_ASSOC), 'grade');

$students = [];
if ($sel_class !== null && !empty($skills)) {
    $studentsStmt = $pdo->prepare("SELECT id, full_name FROM students WHERE school_id = ? AND class_id = ? ORDER BY full_name");
    $studentsStmt->execute([$school_id, $sel_class]);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($students)) {
        $ratingsStmt = $pdo->prepare("
            SELECT student_id, skill_id, rating_grade
            FROM student_skill_ratings
            WHERE school_id = ? AND term = ? AND year = ? AND student_id IN (" . implode(',', array_fill(0, count($students), '?')) . ")
        ");
        $ratingsStmt->execute(array_merge([$school_id, $term, $year], array_column($students, 'id')));
        $existing = [];
        foreach ($ratingsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existing[$r['student_id']][$r['skill_id']] = $r['rating_grade'];
        }
        foreach ($students as &$st) {
            $st['ratings'] = $existing[$st['id']] ?? [];
        }
        unset($st);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Generic Skills — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:1100px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.3rem;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.filters{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:24px;}
.filters .row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:14px;align-items:end;}
@media(max-width:768px){ .filters .row{grid-template-columns:1fr;} }
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select,input[type=text],input[type=number]{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);font-size:0.9rem;font-weight:700;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;}
.section-footer{padding:16px 20px;text-align:right;border-top:1px solid var(--border);}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
table{display:block;overflow-x:auto;}
</style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>
<div class="container">
    <div class="header">
        <h1>Generic Skills — Class Teacher Rating</h1>
        <a href="teachers_portal.php" class="btn-link">&larr; Teacher Portal</a>
    </div>

    <?= $message ?>

    <?php if (empty($classes)): ?>
        <p class="empty">You are not the class teacher of any class yet.</p>
    <?php elseif (empty($skills)): ?>
        <p class="empty">Your school hasn't set up any Generic Skills yet — ask your school admin to add some under Grading Scales.</p>
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
                    <input type="text" name="term" value="<?= htmlspecialchars($term) ?>">
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

    <?php if ($sel_class !== null): ?>
        <?php if (empty($students)): ?>
            <p class="empty">No students found in this class.</p>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="class_id" value="<?= (int) $sel_class ?>">
            <input type="hidden" name="term" value="<?= htmlspecialchars($term) ?>">
            <input type="hidden" name="year" value="<?= htmlspecialchars((string) $year) ?>">
            <div class="section">
                <div class="section-header">Rate Each Student</div>
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <?php foreach ($skills as $sk): ?>
                                <th><?= htmlspecialchars($sk['skill_name']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $st): ?>
                        <tr>
                            <td><?= htmlspecialchars($st['full_name']) ?></td>
                            <?php foreach ($skills as $sk): ?>
                                <td>
                                    <select name="rating[<?= (int) $st['id'] ?>][<?= (int) $sk['id'] ?>]">
                                        <option value="">--</option>
                                        <?php foreach ($grade_options as $g): ?>
                                            <option value="<?= htmlspecialchars($g) ?>" <?= ($st['ratings'][$sk['id']] ?? '') === $g ? 'selected' : '' ?>><?= htmlspecialchars($g) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="section-footer">
                    <button type="submit" name="save_ratings" value="1">Save Ratings</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>

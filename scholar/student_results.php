<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT: MY RESULTS
|--------------------------------------------------------------------------
| Extracted from the old all-in-one student_portal.php so it can sit
| behind the shared sidebar (_student_shell.php) instead of being one
| section on an ever-growing single page. Same read-only, hard-scoped-to-
| current_student_id() query as before -- no behavior change, just its own
| page now.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['student']);

$school_id  = current_school_id();
$student_id = current_student_id();

if ($student_id === 0) {
    http_response_code(403);
    die('This login is not linked to a student record. Ask your school admin to re-create your login.');
}

$stu_stmt = $pdo->prepare("SELECT full_name FROM students WHERE id = ? AND school_id = ?");
$stu_stmt->execute([$student_id, $school_id]);
$student = $stu_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(403);
    die('Student record not found for this school.');
}

// Published marks only — Draft/Open assessments stay invisible until a
// teacher/DOS actually closes them.
$marks_stmt = $pdo->prepare("
    SELECT sm.marks, sm.paper_number, sub.subject_name, sub.papers_count, a.title AS assessment_title, a.term, a.year
    FROM student_marks sm
    JOIN subjects sub ON sub.id = sm.subject_id AND sub.school_id = sm.school_id
    JOIN assessments a ON a.id = sm.assessment_id AND a.school_id = sm.school_id
    WHERE sm.student_id = ? AND sm.school_id = ? AND a.status = 'Closed'
    ORDER BY a.year DESC, a.term DESC, sub.subject_name ASC, sm.paper_number ASC
");
$marks_stmt->execute([$student_id, $school_id]);
$marks = $marks_stmt->fetchAll(PDO::FETCH_ASSOC);

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'results';
require_once __DIR__ . '/_student_shell.php';
?>
<style>
.section-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;font-size:0.9rem;}
th,td{padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.72rem;letter-spacing:0.5px;}
tr:last-child td{border-bottom:none;}
.empty{color:var(--muted);font-size:0.9rem;padding:16px;background:var(--panel);border:1px solid var(--border);border-radius:10px;}
.print-btn{display:inline-block;margin-bottom:18px;padding:10px 18px;border-radius:8px;background:var(--cyan);color:#04121a;font-weight:700;font-size:0.85rem;text-decoration:none;}
table{display:block;overflow-x:auto;}
</style>
<div class="section-title">My Results</div>
<a class="print-btn" href="generate_report.php?term=<?= urlencode(current_term()); ?>&year=<?= urlencode(current_year()); ?>" target="_blank">Print My Report Card</a>
<?php if ($marks): ?>
<table>
    <thead><tr><th>Assessment</th><th>Subject</th><th>Marks</th><th>Term</th></tr></thead>
    <tbody>
    <?php foreach ($marks as $m): ?>
        <tr>
            <td><?= htmlspecialchars($m['assessment_title'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($m['subject_name'] . ((int) $m['papers_count'] > 1 ? ' (Paper ' . (int) $m['paper_number'] . ')' : ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $m['marks'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($m['term'] . ' ' . $m['year'], ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
    <div class="empty">No published results yet. Your school will publish results here once marking is complete.</div>
<?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

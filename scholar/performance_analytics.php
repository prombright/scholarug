<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER: PERFORMANCE ANALYTICS
|--------------------------------------------------------------------------
| Same "pick one of your classes/subjects" flow as teacher_marks_entry.php,
| but reading student_marks instead of writing it. Aggregates every
| SUBMITTED mark (drafts are excluded -- they're not real results yet) for
| the selected class+subject across the assessments in the school's
| current term/year, one combined score per (student, assessment) --
| multi-paper subjects sum their papers, matching how a report card would.
|
| If the viewing teacher is also this class's class_teacher_id, a second
| section below repeats the same analysis across EVERY subject the class
| takes, not just the one selected -- the "bigger view" a class teacher
| needs that a subject teacher doesn't.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/_analytics_helpers.php';
require_role(['teacher']);

$school_id = current_school_id();
$staff_id = current_staff_id();

$school_stmt = $pdo->prepare('SELECT current_term, current_year FROM schools WHERE id = ?');
$school_stmt->execute([$school_id]);
$school_row = $school_stmt->fetch() ?: [];
$current_term = $school_row['current_term'] ?? 'Term 1';
$current_year = (int) ($school_row['current_year'] ?? date('Y'));

$assigned_stmt = $pdo->prepare("
    SELECT DISTINCT ta.class_id, c.class_name, ta.subject_id, s.subject_name, s.subject_code
    FROM teacher_assignments ta
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    WHERE ta.school_id = ? AND ta.teacher_id = ?
    ORDER BY c.class_name, s.subject_name
");
$assigned_stmt->execute([$school_id, $staff_id]);
$my_assignments = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

$sel_class = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
$sel_subject = isset($_GET['subject_id']) ? (int) $_GET['subject_id'] : 0;

$sel_assignment = null;
foreach ($my_assignments as $a) {
    if ((int) $a['class_id'] === $sel_class && (int) $a['subject_id'] === $sel_subject) {
        $sel_assignment = $a;
        break;
    }
}
if ($sel_class && $sel_subject && !$sel_assignment) {
    $sel_class = 0;
    $sel_subject = 0;
}

$subject_analytics = null;
$class_analytics = null;
$is_class_teacher_here = false;

if ($sel_assignment) {
    $view = analytics_build_view($pdo, $school_id, $staff_id, $sel_class, $sel_subject, $current_term, $current_year);
    $subject_analytics = $view['subject_analytics'];
    $class_analytics = $view['class_analytics'];
    $is_class_teacher_here = $view['is_class_teacher_here'];
}

$class_teacher_stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE school_id = ? AND class_teacher_id = ?');
$class_teacher_stmt->execute([$school_id, $staff_id]);
$is_any_class_teacher = (int) $class_teacher_stmt->fetchColumn() > 0;

$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.teacher_id = ? AND cv.school_id = ? AND cm.sender_role = 'student' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$staff_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

$__school_brand = $pdo->prepare('SELECT school_name, school_badge FROM schools WHERE id = ?');
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'analytics';
require_once __DIR__ . '/_teacher_shell.php';
?>
<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 4px;}
.page-sub{color:var(--muted);font-size:0.85rem;margin:0 0 18px;}
.breadcrumb{font-size:0.8rem;color:var(--muted);margin-bottom:18px;}
.breadcrumb a{color:var(--cyan);text-decoration:none;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:10px;}
.pick-card{display:block;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;text-decoration:none;color:var(--text);transition:border-color .15s,transform .15s;}
.pick-card:hover{border-color:var(--cyan);transform:translateY(-2px);}
.pick-card h3{margin:0 0 6px;font-size:1rem;}
.pick-card .sub{color:var(--muted);font-size:0.78rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:22px;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);font-size:0.95rem;font-weight:700;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;padding:20px;}
.stat-card{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px 16px;}
.stat-card .n{font-size:1.5rem;font-weight:800;}
.stat-card .l{font-size:0.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.highlight-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;padding:0 20px 20px;}
.highlight-card{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:18px;}
.highlight-card .tag{font-size:0.68rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--cyan);font-weight:700;margin-bottom:8px;}
.highlight-card .name{font-size:1.05rem;font-weight:700;}
.highlight-card .detail{font-size:0.78rem;color:var(--muted);margin-top:4px;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:11px 20px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;letter-spacing:0.5px;background:rgba(255,255,255,0.02);}
tbody tr:last-child td{border-bottom:none;}
.rank{color:var(--muted);font-weight:700;}
.trend-up{color:var(--green);}
.trend-down{color:var(--danger,#ef4444);}
.trend-flat{color:var(--muted);}
.badge-winner{background:rgba(16,185,129,0.15);color:var(--green);font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;text-transform:uppercase;}
.badge-consistent{background:rgba(0,168,168,0.15);color:var(--cyan);font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:8px;text-transform:uppercase;}
table{display:block;overflow-x:auto;}
</style>
<div class="page-title">Performance Analytics</div>

<?php if (!$sel_assignment): ?>

    <p class="page-sub">Choose one of your classes to see how its students are performing (<?= htmlspecialchars($current_term . ' ' . $current_year, ENT_QUOTES, 'UTF-8') ?>).</p>

    <?php if (empty($my_assignments)): ?>
        <p class="empty">You have no class/subject assignments yet — ask your school admin to assign you via Teacher Assignments.</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($my_assignments as $a): ?>
                <a class="pick-card" href="?class_id=<?= (int) $a['class_id'] ?>&subject_id=<?= (int) $a['subject_id'] ?>">
                    <h3><?= htmlspecialchars($a['class_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <div class="sub"><?= htmlspecialchars($a['subject_name'] . ' (' . $a['subject_code'] . ')', ENT_QUOTES, 'UTF-8') ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>

    <div class="breadcrumb">
        <a href="?">All Classes</a> &rsaquo;
        <?= htmlspecialchars($sel_assignment['class_name'] . ' — ' . $sel_assignment['subject_name'], ENT_QUOTES, 'UTF-8') ?>
    </div>

    <?php if (empty($subject_analytics['students'])): ?>
        <p class="empty">No submitted marks yet for <?= htmlspecialchars($current_term . ' ' . $current_year, ENT_QUOTES, 'UTF-8') ?> — analytics will appear here once marks are submitted (not just saved as draft).</p>
    <?php else: $sa = $subject_analytics; ?>

        <div class="section">
            <div class="section-header"><?= htmlspecialchars($sel_assignment['subject_name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($sel_assignment['class_name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="stat-grid">
                <div class="stat-card"><div class="n"><?= $sa['class_average'] ?></div><div class="l">Class Average</div></div>
                <div class="stat-card"><div class="n"><?= $sa['class_highest'] ?></div><div class="l">Highest Average</div></div>
                <div class="stat-card"><div class="n"><?= $sa['class_lowest'] ?></div><div class="l">Lowest Average</div></div>
                <div class="stat-card"><div class="n"><?= $sa['assessment_count'] ?></div><div class="l">Assessments Counted</div></div>
                <div class="stat-card"><div class="n"><?= $sa['gender']['male_avg'] ?? '—' ?></div><div class="l">Boys Avg (<?= $sa['gender']['male_count'] ?>)</div></div>
                <div class="stat-card"><div class="n"><?= $sa['gender']['female_avg'] ?? '—' ?></div><div class="l">Girls Avg (<?= $sa['gender']['female_count'] ?>)</div></div>
            </div>
            <div class="highlight-grid">
                <div class="highlight-card">
                    <div class="tag">🏆 Overall Winner</div>
                    <div class="name"><?= $sa['winner'] ? htmlspecialchars($sa['winner']['full_name'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
                    <div class="detail"><?= $sa['winner'] ? 'Average: ' . $sa['winner']['average'] : 'No data yet' ?></div>
                </div>
                <div class="highlight-card">
                    <div class="tag">🎯 Most Consistent</div>
                    <div class="name"><?= $sa['most_consistent'] ? htmlspecialchars($sa['most_consistent']['full_name'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
                    <div class="detail"><?= $sa['most_consistent'] ? 'Spread: ±' . $sa['most_consistent']['stddev'] . ' across ' . $sa['most_consistent']['count'] . ' assessments' : 'Needs 2+ submitted assessments' ?></div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">Full Ranking</div>
            <table>
                <thead><tr><th>#</th><th>Student</th><th>Average</th><th>Highest</th><th>Lowest</th><th>Consistency</th><th>Trend</th></tr></thead>
                <tbody>
                <?php foreach ($sa['students'] as $i => $s): ?>
                    <tr>
                        <td class="rank">#<?= $i + 1 ?></td>
                        <td>
                            <?= htmlspecialchars($s['full_name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($sa['winner'] && $s['student_id'] === $sa['winner']['student_id']): ?><span class="badge-winner">Winner</span><?php endif; ?>
                            <?php if ($sa['most_consistent'] && $s['student_id'] === $sa['most_consistent']['student_id']): ?><span class="badge-consistent">Consistent</span><?php endif; ?>
                        </td>
                        <td><?= $s['average'] ?></td>
                        <td><?= $s['highest'] ?></td>
                        <td><?= $s['lowest'] ?></td>
                        <td><?= $s['stddev'] !== null ? '±' . $s['stddev'] : '—' ?></td>
                        <td class="trend-<?= $s['trend'] ?>"><?= ['up' => '▲ Improving', 'down' => '▼ Declining', 'flat' => '— Steady'][$s['trend']] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <?php if ($is_class_teacher_here && $class_analytics && !empty($class_analytics['students'])): $ca = $class_analytics; ?>

        <div class="page-title" style="margin-top:32px;">Whole-Class Overview</div>
        <p class="page-sub">You're the class teacher for <?= htmlspecialchars($sel_assignment['class_name'], ENT_QUOTES, 'UTF-8') ?> — this combines every subject the class takes, not just <?= htmlspecialchars($sel_assignment['subject_name'], ENT_QUOTES, 'UTF-8') ?>.</p>

        <div class="section">
            <div class="section-header">Class-Wide Standing</div>
            <div class="highlight-grid" style="padding-top:20px;">
                <div class="highlight-card">
                    <div class="tag">🏆 Overall Winner</div>
                    <div class="name"><?= $ca['winner'] ? htmlspecialchars($ca['winner']['full_name'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
                    <div class="detail"><?= $ca['winner'] ? 'Overall average: ' . $ca['winner']['average'] . ' across ' . $ca['winner']['subjects_count'] . ' subject(s)' : 'No data yet' ?></div>
                </div>
                <div class="highlight-card">
                    <div class="tag">🎯 Most Consistent</div>
                    <div class="name"><?= $ca['most_consistent'] ? htmlspecialchars($ca['most_consistent']['full_name'], ENT_QUOTES, 'UTF-8') : '—' ?></div>
                    <div class="detail"><?= $ca['most_consistent'] ? 'Spread: ±' . $ca['most_consistent']['stddev'] : 'Needs 2+ submitted assessments' ?></div>
                </div>
                <div class="highlight-card">
                    <div class="tag">Gender Comparison</div>
                    <div class="name">Boys <?= $ca['gender']['male_avg'] ?? '—' ?> · Girls <?= $ca['gender']['female_avg'] ?? '—' ?></div>
                    <div class="detail"><?= $ca['gender']['male_count'] ?> boys, <?= $ca['gender']['female_count'] ?> girls with results on file</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">Subjects Ranked (Class Average)</div>
            <table>
                <thead><tr><th>#</th><th>Subject</th><th>Class Average</th></tr></thead>
                <tbody>
                <?php if (empty($ca['subjects_ranked'])): ?>
                    <tr><td colspan="3" class="empty">No data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($ca['subjects_ranked'] as $i => $sub): ?>
                    <tr><td class="rank">#<?= $i + 1 ?></td><td><?= htmlspecialchars($sub['subject_name'], ENT_QUOTES, 'UTF-8') ?></td><td><?= $sub['average'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="section-header">Whole-Class Ranking (All Subjects)</div>
            <table>
                <thead><tr><th>#</th><th>Student</th><th>Overall Average</th><th>Consistency</th></tr></thead>
                <tbody>
                <?php foreach ($ca['students'] as $i => $s): ?>
                    <tr>
                        <td class="rank">#<?= $i + 1 ?></td>
                        <td>
                            <?= htmlspecialchars($s['full_name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($ca['winner'] && $s['student_id'] === $ca['winner']['student_id']): ?><span class="badge-winner">Winner</span><?php endif; ?>
                            <?php if ($ca['most_consistent'] && $s['student_id'] === $ca['most_consistent']['student_id']): ?><span class="badge-consistent">Consistent</span><?php endif; ?>
                        </td>
                        <td><?= $s['average'] ?></td>
                        <td><?= $s['stddev'] !== null ? '±' . $s['stddev'] : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

<?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

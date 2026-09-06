<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — DOS (DIRECTOR OF STUDIES) DASHBOARD
|--------------------------------------------------------------------------
| Academic oversight: subject coverage per class, a read-only list of
| every student's report card (DOS can view and print, never edit --
| generate_report.php has no write path at all), plus the one write
| action DOS does have -- creating/managing assessments (assessments.php,
| shared with school_admin) so a Director of Studies can set up an AOI,
| Midterm, or EOT without needing the school_admin account.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['dos']);

$school_id = current_school_id();

// NOTE: this used to join a table called `subject_assignments`, which does
// not exist anywhere in the schema (the real table is `teacher_assignments`,
// keyed by teacher_id -> staff.staff_id) -- so this query threw an uncaught
// PDOException and the DOS dashboard fataled on every single load.
// A subject with more than one paper can have multiple teacher_assignments
// rows (one teacher per paper) -- GROUP_CONCAT + GROUP BY collapses those
// back to one coverage row instead of one row per paper/teacher.
$coverage = $pdo->prepare("
    SELECT sub.subject_name, sub.class_name, sub.level_type,
           GROUP_CONCAT(DISTINCT CONCAT(st.first_name, ' ', st.last_name) ORDER BY st.first_name SEPARATOR ', ') AS teacher_name
    FROM subjects sub
    LEFT JOIN teacher_assignments ta ON ta.subject_id = sub.id AND ta.school_id = sub.school_id
    LEFT JOIN staff st ON st.staff_id = ta.teacher_id AND st.school_id = sub.school_id
    WHERE sub.school_id = ?
    GROUP BY sub.id
    ORDER BY sub.class_name, sub.subject_name
");
$coverage->execute([$school_id]);
$coverage = $coverage->fetchAll();

$assigned = 0;
$unassigned = 0;
foreach ($coverage as $row) {
    if (!empty(trim($row['teacher_name'] ?? ''))) { $assigned++; } else { $unassigned++; }
}

$students = $pdo->prepare("
    SELECT id, full_name, student_no, class_name
    FROM students
    WHERE school_id = ?
    ORDER BY class_name, full_name
");
$students->execute([$school_id]);
$students = $students->fetchAll();

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DOS Dashboard</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --purple:#a855f7; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:1100px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;position:sticky;top:0;z-index:20;background:var(--bg);padding:12px 0;}
.badge{font-size:0.7rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--purple);border:1px solid var(--purple);padding:4px 10px;border-radius:6px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;display:flex;align-items:center;gap:14px;}
.card-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;background:rgba(0,168,168,.15);color:var(--cyan);}
.card.ok-card .card-icon{background:rgba(16,185,129,.15);color:var(--green);}
.card.gap-card .card-icon{background:rgba(239,68,68,.15);color:var(--danger);}
.card .n{font-size:1.7rem;font-weight:700;color:var(--text);}
.card .label{color:var(--muted);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.notice{background:rgba(168,85,247,0.08);border:1px solid rgba(168,85,247,0.3);border-radius:8px;padding:14px 16px;font-size:0.85rem;color:var(--text);margin-bottom:20px;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;}
.gap{color:var(--danger);font-weight:600;}
.ok{color:var(--green);}
.logout{color:var(--danger);text-decoration:none;font-size:0.75rem;font-weight:700;text-transform:uppercase;border:1px solid rgba(239,68,68,0.3);padding:8px 16px;border-radius:6px;}
table{display:block;overflow-x:auto;}
@media (max-width:480px){.header{flex-wrap:wrap;gap:10px;}}

.reveal{opacity:0;transform:translateY(16px);transition:opacity .5s ease, transform .5s ease;}
.reveal.revealed{opacity:1;transform:translateY(0);}
.chart-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:20px;display:flex;flex-direction:column;align-items:center;}
.chart-card h2{font-size:0.9rem;margin:0 0 18px;align-self:flex-start;}
.donut{width:150px;height:150px;border-radius:50%;margin-bottom:18px;position:relative;transform:scale(.7);opacity:0;transition:transform .6s cubic-bezier(.22,1,.36,1), opacity .6s ease;}
.reveal.revealed .donut{transform:scale(1);opacity:1;}
.donut::after{content:'';position:absolute;inset:20px;background:var(--panel);border-radius:50%;}
.donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.donut-center .n{font-size:1.4rem;font-weight:700;}
.donut-center .label{font-size:0.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
.donut-legend{display:flex;gap:18px;font-size:0.8rem;color:var(--muted);}
.donut-legend .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;}
@media (prefers-reduced-motion: reduce){
    .reveal{opacity:1;transform:none;transition:none;}
    .donut{transition:none;transform:none;opacity:1;}
}
</style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>
<div class="container">
    <div class="header">
        <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($__badge_url): ?>
                <img src="<?= htmlspecialchars($__badge_url) ?>?t=<?= time() ?>" alt="" style="width:40px;height:40px;object-fit:contain;border-radius:6px;">
            <?php else: ?>
                <div style="width:40px;height:40px;border-radius:6px;background:var(--purple);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;"><?= htmlspecialchars(strtoupper(substr($__school_brand['school_name'] ?? 'S', 0, 1))) ?></div>
            <?php endif; ?>
            <div><h1 style="margin:0;font-size:1.4rem;">Director of Studies</h1><span class="badge">View Only</span></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <a href="app_admin.php#/assessments" class="logout" style="color:var(--cyan);border-color:rgba(0,168,168,0.3);">Manage Assessments</a>
            <a href="school_admin/bulk_report_print.php" class="logout" style="color:var(--cyan);border-color:rgba(0,168,168,0.3);">Bulk Print Reports</a>
            <a href="school_admin/remarks.php" class="logout" style="color:var(--cyan);border-color:rgba(0,168,168,0.3);">Report Remarks</a>
            <a href="leave_requests.php" class="logout" style="color:var(--cyan);border-color:rgba(0,168,168,0.3);">My Leave</a>
            <a class="scholar-logout-btn" href="logout.php">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </div>
    </div>

    <div class="notice">
        View-only: you can open and print any student's report card below,
        and create/manage assessments (AOI, Midterm, EOT). Marks themselves
        are entered by teachers — this dashboard has no way to change them.
    </div>

    <div class="grid reveal">
        <div class="card"><div class="card-icon"><i class="bi bi-journal-text"></i></div><div><div class="n" data-count="<?= count($coverage) ?>"><?= count($coverage) ?></div><div class="label">Subjects Offered</div></div></div>
        <div class="card ok-card"><div class="card-icon"><i class="bi bi-check-circle"></i></div><div><div class="n ok" data-count="<?= $assigned ?>"><?= $assigned ?></div><div class="label">Assigned to a Teacher</div></div></div>
        <div class="card gap-card"><div class="card-icon"><i class="bi bi-exclamation-triangle"></i></div><div><div class="n gap" data-count="<?= $unassigned ?>"><?= $unassigned ?></div><div class="label">Unassigned</div></div></div>
    </div>

    <?php if (count($coverage) > 0): ?>
    <?php $assigned_pct = round($assigned / count($coverage) * 100); ?>
    <div class="chart-card reveal">
        <h2>Teacher Coverage</h2>
        <div class="donut" style="background:conic-gradient(var(--green) 0% <?= $assigned_pct ?>%, var(--danger) <?= $assigned_pct ?>% 100%);">
            <div class="donut-center">
                <div class="n"><?= $assigned_pct ?>%</div>
                <div class="label">Covered</div>
            </div>
        </div>
        <div class="donut-legend">
            <span><span class="dot" style="background:var(--green);"></span>Assigned <?= $assigned ?></span>
            <span><span class="dot" style="background:var(--danger);"></span>Unassigned <?= $unassigned ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2 style="font-size:1rem;margin:0 0 14px;">Subject Coverage</h2>
        <table>
            <tr><th>Class</th><th>Level</th><th>Subject</th><th>Teacher</th></tr>
            <?php foreach ($coverage as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['class_name'] ?? '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($c['level_type'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($c['subject_name'], ENT_QUOTES) ?></td>
                <td class="<?= trim($c['teacher_name'] ?? '') ? 'ok' : 'gap' ?>">
                    <?= htmlspecialchars(trim($c['teacher_name'] ?? '') ?: 'Unassigned', ENT_QUOTES) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="section">
        <h2 style="font-size:1rem;margin:0 0 14px;">Student Reports <span class="badge">View Only</span></h2>
        <table>
            <tr><th>Adm. No.</th><th>Name</th><th>Class</th><th></th></tr>
            <?php foreach ($students as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['student_no'] ?? '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($s['full_name'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($s['class_name'] ?? '—', ENT_QUOTES) ?></td>
                <td><a href="generate_report.php?student_id=<?= (int) $s['id'] ?>&term=<?= urlencode(current_term()) ?>&year=<?= urlencode(current_year()) ?>" target="_blank" style="color:var(--cyan);">View Report</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<script src="assets/js/dashboard-effects.js"></script>
</body>
</html>

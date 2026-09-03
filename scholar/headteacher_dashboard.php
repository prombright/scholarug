<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — HEADTEACHER DASHBOARD (read-only)
|--------------------------------------------------------------------------
| Headteacher sees everything happening in their school — students, staff,
| classes, attendance, announcements — but every query here is a SELECT.
| There is intentionally no form on this page that writes to the database.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['headteacher']);

$school_id = current_school_id();

$school = $pdo->prepare("SELECT school_name, school_badge, location FROM schools WHERE id = ?");
$school->execute([$school_id]);
$school = $school->fetch() ?: [];

// Fetched above but was never actually rendered anywhere on this page --
// every other role dashboard shows the school's badge in its header, this
// one silently didn't.
$__badge_url = null;
if (!empty($school['school_badge']) && file_exists(__DIR__ . '/' . $school['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($school['school_badge'], '/');
}

$student_count = $pdo->prepare("SELECT COUNT(*) FROM students WHERE school_id = ?");
$student_count->execute([$school_id]);
$student_count = (int) $student_count->fetchColumn();

$staff_count = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE school_id = ?");
$staff_count->execute([$school_id]);
$staff_count = (int) $staff_count->fetchColumn();

$classes = $pdo->prepare("
    SELECT c.id, c.class_name, c.stream_name,
           CONCAT(st.first_name, ' ', st.last_name) AS class_teacher_name
    FROM classes c
    LEFT JOIN staff st ON st.staff_id = c.class_teacher_id
    WHERE c.school_id = ?
    ORDER BY c.class_name, c.stream_name
");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

// attendance has no school_id column of its own — scope it via class_id -> classes.school_id
$today_attendance = $pdo->prepare("
    SELECT a.status, COUNT(*) AS n
    FROM attendance a
    JOIN classes c ON c.id = a.class_id
    WHERE c.school_id = ? AND a.attendance_date = CURDATE()
    GROUP BY a.status
");
$today_attendance->execute([$school_id]);
$attendance_today = $today_attendance->fetchAll(PDO::FETCH_KEY_PAIR);

$announcements = $pdo->prepare("
    SELECT title, message, audience, created_at
    FROM announcements
    WHERE school_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$announcements->execute([$school_id]);
$announcements = $announcements->fetchAll();

// attendance.status is enum('present','absent','sick','permission') -- build
// the donut from whichever of the 4 actually have rows today, not just the
// 2 already shown as stat cards, so a school that tracks "sick"/"permission"
// isn't silently left out of its own attendance breakdown.
$attendance_colors = ['present' => 'var(--green)', 'absent' => 'var(--danger)', 'sick' => 'var(--amber)', 'permission' => 'var(--purple)'];
$attendance_total_today = array_sum($attendance_today);
$attendance_gradient_stops = [];
$attendance_legend = [];
$__cursor = 0;
if ($attendance_total_today > 0) {
    foreach ($attendance_colors as $status => $color) {
        $n = (int) ($attendance_today[$status] ?? 0);
        if ($n === 0) {
            continue;
        }
        $pct = round($n / $attendance_total_today * 100);
        $attendance_gradient_stops[] = "{$color} {$__cursor}% " . ($__cursor + $pct) . '%';
        $__cursor += $pct;
        $attendance_legend[] = ['label' => ucfirst($status), 'n' => $n, 'color' => $color];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Headteacher Dashboard — <?= htmlspecialchars($school['school_name'] ?? 'Scholar', ENT_QUOTES) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --purple:#a855f7; --green:#10b981; --amber:#f59e0b; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:1200px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;position:sticky;top:0;z-index:20;background:var(--bg);padding:12px 0;}
.header h1{font-size:1.4rem;margin:0;}
.badge{font-size:0.7rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--purple);border:1px solid var(--purple);padding:4px 10px;border-radius:6px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;display:flex;align-items:center;gap:14px;}
.card-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;background:rgba(0,168,168,.15);color:var(--cyan);}
.card.students .card-icon{background:rgba(0,168,168,.15);color:var(--cyan);}
.card.staff .card-icon{background:rgba(16,185,129,.15);color:var(--green);}
.card.classes .card-icon{background:rgba(245,158,11,.15);color:var(--amber);}
.card.present .card-icon{background:rgba(16,185,129,.15);color:var(--green);}
.card.absent .card-icon{background:rgba(239,68,68,.15);color:var(--danger);}
.card .n{font-size:1.7rem;font-weight:700;color:var(--text);}
.card .label{color:var(--muted);font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.section h2{font-size:1rem;margin:0 0 14px;color:var(--text);}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;}
.announcement{padding:12px 0;border-bottom:1px solid var(--border);}
.announcement:last-child{border-bottom:none;}
.announcement .meta{color:var(--muted);font-size:0.75rem;}
.logout{color:var(--danger);text-decoration:none;font-size:0.75rem;font-weight:700;text-transform:uppercase;border:1px solid rgba(239,68,68,0.3);padding:8px 16px;border-radius:6px;}
.empty{color:var(--muted);font-size:0.85rem;padding:8px 0;}
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
.donut-legend{display:flex;flex-wrap:wrap;justify-content:center;gap:14px;font-size:0.8rem;color:var(--muted);}
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
                <img src="<?= htmlspecialchars($__badge_url) ?>?t=<?= time() ?>" alt="" style="width:40px;height:40px;object-fit:contain;border-radius:6px;flex-shrink:0;">
            <?php else: ?>
                <div style="width:40px;height:40px;border-radius:6px;background:var(--purple);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;"><?= htmlspecialchars(strtoupper(substr($school['school_name'] ?? 'S', 0, 1))) ?></div>
            <?php endif; ?>
            <div>
                <h1 style="margin:0;"><?= htmlspecialchars($school['school_name'] ?? 'Scholar', ENT_QUOTES) ?></h1>
                <span class="badge">Headteacher · View Only</span>
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <a href="school_admin/bulk_report_print.php" class="logout" style="color:var(--cyan);border-color:rgba(0,168,168,0.3);">Bulk Print Reports</a>
            <a href="school_admin/remarks.php" class="logout" style="color:var(--cyan);border-color:rgba(0,168,168,0.3);">Report Remarks</a>
            <a href="leave_requests.php" class="logout" style="color:var(--cyan);border-color:rgba(0,168,168,0.3);">My Leave</a>
            <a class="scholar-logout-btn" href="logout.php">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </div>
    </div>

    <div class="grid reveal">
        <div class="card students"><div class="card-icon"><i class="bi bi-people"></i></div><div><div class="n" data-count="<?= $student_count ?>"><?= $student_count ?></div><div class="label">Students</div></div></div>
        <div class="card staff"><div class="card-icon"><i class="bi bi-person-badge"></i></div><div><div class="n" data-count="<?= $staff_count ?>"><?= $staff_count ?></div><div class="label">Staff</div></div></div>
        <div class="card classes"><div class="card-icon"><i class="bi bi-diagram-3"></i></div><div><div class="n" data-count="<?= count($classes) ?>"><?= count($classes) ?></div><div class="label">Classes</div></div></div>
        <div class="card present"><div class="card-icon"><i class="bi bi-check-circle"></i></div><div><div class="n" data-count="<?= (int)($attendance_today['present'] ?? 0) ?>"><?= (int)($attendance_today['present'] ?? 0) ?></div><div class="label">Present Today</div></div></div>
        <div class="card absent"><div class="card-icon"><i class="bi bi-x-circle"></i></div><div><div class="n" data-count="<?= (int)($attendance_today['absent'] ?? 0) ?>"><?= (int)($attendance_today['absent'] ?? 0) ?></div><div class="label">Absent Today</div></div></div>
    </div>

    <?php if ($attendance_total_today > 0): ?>
    <div class="chart-card reveal">
        <h2>Today's Attendance</h2>
        <div class="donut" style="background:conic-gradient(<?= implode(', ', $attendance_gradient_stops) ?>);">
            <div class="donut-center">
                <div class="n"><?= $attendance_total_today ?></div>
                <div class="label">Recorded</div>
            </div>
        </div>
        <div class="donut-legend">
            <?php foreach ($attendance_legend as $entry): ?>
                <span><span class="dot" style="background:<?= $entry['color'] ?>;"></span><?= htmlspecialchars($entry['label']) ?> <?= $entry['n'] ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>Classes &amp; Class Teachers</h2>
        <?php if ($classes): ?>
        <table>
            <tr><th>Class</th><th>Stream</th><th>Class Teacher</th></tr>
            <?php foreach ($classes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['class_name'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($c['stream_name'] ?? '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars(trim($c['class_teacher_name'] ?? '') ?: 'Not assigned', ENT_QUOTES) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
            <div class="empty">No classes set up yet.</div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Recent Announcements</h2>
        <?php if ($announcements): ?>
            <?php foreach ($announcements as $a): ?>
            <div class="announcement">
                <strong><?= htmlspecialchars($a['title'] ?? '', ENT_QUOTES) ?></strong>
                <div><?= htmlspecialchars($a['message'] ?? '', ENT_QUOTES) ?></div>
                <div class="meta"><?= htmlspecialchars($a['audience'] ?? 'all', ENT_QUOTES) ?> · <?= htmlspecialchars($a['created_at'] ?? '', ENT_QUOTES) ?></div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty">No announcements yet.</div>
        <?php endif; ?>
    </div>
</div>
<script src="assets/js/dashboard-effects.js"></script>
</body>
</html>

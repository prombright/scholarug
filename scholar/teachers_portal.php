<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — TEACHER PORTAL (LANDING)
|--------------------------------------------------------------------------
| Used to BE the marks-entry tool directly -- that's moved to
| teacher_marks_entry.php now. This is a pure card-grid landing,
| deliberately no sidebar (see _teacher_shell.php's header comment). Each
| card links into its own page, which DOES show the shared sidebar.
|--------------------------------------------------------------------------
*/

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}

// Superseded by the Vue teacher SPA (app_teacher.php) -- kept only so old
// bookmarks/links to this URL still land somewhere useful.
header("Location: " . SCHOLAR_BASE . "/app_teacher.php");
exit();

$school_id = $_SESSION['school_id'];
$staff_id  = $_SESSION['staff_id'];

// Is this teacher the class teacher of anything? (unlocks 3 extra cards)
$class_teacher_stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND class_teacher_id = ?");
$class_teacher_stmt->execute([$school_id, $staff_id]);
$is_any_class_teacher = (int) $class_teacher_stmt->fetchColumn() > 0;

// Unread student messages across every conversation this teacher is in.
$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.teacher_id = ? AND cv.school_id = ? AND cm.sender_role = 'student' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$staff_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

// How many distinct class/subject assignments this teacher has (teaser on
// the Marks Entry card).
$assign_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM teacher_assignments WHERE school_id = ? AND teacher_id = ?");
$assign_count_stmt->execute([$school_id, $staff_id]);
$assignment_count = (int) $assign_count_stmt->fetchColumn();

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

// Plain rgba() instead of CSS color-mix() -- some budget Android browsers
// still in use at schools don't support color-mix() yet.
function hex_to_tint(string $hex, float $alpha = 0.16): string
{
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    return "rgba($r, $g, $b, $alpha)";
}

$cards = [
    [
        'href' => 'teacher_marks_entry.php', 'icon' => 'bi-pencil-square', 'color' => '#00A8A8',
        'title' => 'Marks Entry', 'desc' => 'Enter or import assessment marks for your classes.',
        'stat' => $assignment_count . ' assignment' . ($assignment_count === 1 ? '' : 's'),
        'stat_n' => $assignment_count, 'stat_suffix' => ' assignment' . ($assignment_count === 1 ? '' : 's'),
    ],
    [
        'href' => 'performance_analytics.php', 'icon' => 'bi-bar-chart-line', 'color' => '#00A8A8',
        'title' => 'Performance Analytics', 'desc' => 'Class averages, gender comparison, top performer and the most consistent student.',
        'stat' => null,
    ],
    [
        'href' => 'my_timetable.php', 'icon' => 'bi-calendar3', 'color' => '#f59e0b',
        'title' => 'My Timetable', 'desc' => 'Your weekly teaching schedule.',
        'stat' => null,
    ],
    [
        'href' => 'teacher_attendance.php', 'icon' => 'bi-calendar-check', 'color' => '#10b981',
        'title' => 'Roll Call', 'desc' => 'Take attendance for any class in the school.',
        'stat' => null,
    ],
    [
        'href' => 'library/manage.php', 'icon' => 'bi-book', 'color' => '#8b5cf6',
        'title' => 'Library', 'desc' => 'Upload notes and past papers for your classes.',
        'stat' => null,
    ],
    [
        'href' => 'teacher_messages.php', 'icon' => 'bi-chat-dots', 'color' => '#3b82f6',
        'title' => 'Messages', 'desc' => 'Talk directly with your students.',
        'stat' => $unread_message_count > 0 ? $unread_message_count . ' unread' : null,
        'stat_class' => $unread_message_count > 0 ? 'danger' : null,
        'stat_n' => $unread_message_count > 0 ? $unread_message_count : null, 'stat_suffix' => ' unread',
    ],
    [
        'href' => 'leave_requests.php', 'icon' => 'bi-calendar-minus', 'color' => '#14b8a6',
        'title' => 'My Leave', 'desc' => 'Apply for leave and track your requests.',
        'stat' => null,
    ],
    [
        'href' => 'projects/teacher_project.php', 'icon' => 'bi-kanban', 'color' => '#f97316',
        'title' => 'Project Work', 'desc' => 'Log stage progress for a class project you monitor.',
        'stat' => null,
    ],
];
if ($is_any_class_teacher) {
    $cards[] = [
        'href' => 'school_admin/bulk_report_print.php', 'icon' => 'bi-printer', 'color' => '#ec4899',
        'title' => "Print Class Reports", 'desc' => "Bulk-print report cards for your class.",
        'stat' => null,
    ];
    $cards[] = [
        'href' => 'school_admin/remarks.php', 'icon' => 'bi-chat-square-text', 'color' => '#8b5cf6',
        'title' => "Class Remarks", 'desc' => "Write each student's report card remark.",
        'stat' => null,
    ];
    $cards[] = [
        'href' => 'generic_skills_entry.php', 'icon' => 'bi-star', 'color' => '#eab308',
        'title' => 'Generic Skills', 'desc' => "Rate your class's generic skills.",
        'stat' => null,
    ];
    $cards[] = [
        'href' => 'teacher_class_logins.php', 'icon' => 'bi-key', 'color' => '#64748b',
        'title' => "Students' Logins", 'desc' => 'Generate/reset logins for your class.',
        'stat' => null,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Portal — Scholar</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; --amber:#f59e0b; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:1080px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;flex-wrap:wrap;gap:12px;position:sticky;top:0;z-index:20;background:var(--bg);padding:12px 0;}
.header h1{margin:0;font-size:1.3rem;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;}
.feature-card{display:block;background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;text-decoration:none;color:var(--text);transition:transform .15s,border-color .15s,box-shadow .15s;position:relative;overflow:hidden;}
.feature-card:hover{transform:translateY(-4px);border-color:var(--icon-color, var(--cyan));box-shadow:0 12px 30px rgba(0,0,0,.3);}
.feature-card .icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:16px;background:var(--icon-tint);color:var(--icon-color);}
.feature-card h3{margin:0 0 6px;font-size:1.05rem;}
.feature-card p{margin:0;color:var(--muted);font-size:0.82rem;line-height:1.5;}
.feature-card .stat{display:inline-block;margin-top:14px;font-size:0.75rem;font-weight:700;padding:4px 10px;border-radius:20px;background:rgba(148,163,184,0.15);color:var(--muted);}
.feature-card .stat.danger{background:rgba(239,68,68,0.14);color:var(--danger);}
.feature-card .arrow{position:absolute;top:22px;right:22px;color:var(--muted);font-size:1.1rem;opacity:0;transition:opacity .15s,transform .15s;}
.feature-card:hover .arrow{opacity:1;transform:translateX(3px);}
@media (max-width:480px){.header{flex-wrap:wrap;gap:10px;}}
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
                <div style="width:40px;height:40px;border-radius:6px;background:var(--cyan);color:#04222a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;"><?= htmlspecialchars(strtoupper(substr($__school_brand['school_name'] ?? 'S', 0, 1))) ?></div>
            <?php endif; ?>
            <h1>Teacher Portal</h1>
        </div>
        <a class="scholar-logout-btn" href="logout.php">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Log Out
        </a>
    </div>

    <div class="card-grid reveal">
        <?php foreach ($cards as $c): ?>
            <a class="feature-card" href="<?= htmlspecialchars($c['href'], ENT_QUOTES, 'UTF-8') ?>" style="--icon-color:<?= htmlspecialchars($c['color'], ENT_QUOTES, 'UTF-8') ?>;--icon-tint:<?= htmlspecialchars(hex_to_tint($c['color']), ENT_QUOTES, 'UTF-8') ?>;">
                <i class="bi bi-arrow-right arrow"></i>
                <div class="icon"><i class="bi <?= htmlspecialchars($c['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></div>
                <h3><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= htmlspecialchars($c['desc'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!empty($c['stat'])): ?>
                    <span class="stat <?= htmlspecialchars($c['stat_class'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($c['stat_n'])): ?>
                            <span data-count="<?= (int) $c['stat_n'] ?>"><?= (int) $c['stat_n'] ?></span><?= htmlspecialchars($c['stat_suffix'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <?= htmlspecialchars($c['stat'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<script src="assets/js/dashboard-effects.js"></script>
</body>
</html>

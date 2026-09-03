<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT PORTAL (LANDING)
|--------------------------------------------------------------------------
| Pure card-grid landing -- deliberately no sidebar here (see
| _student_shell.php's header comment). Each card links into its own page
| (student_results.php, student_fees.php, student_attendance.php,
| Library, elections, messages), which DO show the shared sidebar so you
| can jump between them without coming back to this grid every time.
|
| Still read-only and hard-scoped to current_student_id() + school_id --
| same rule as the rest of this file's original single-page version.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/elections/_election_helpers.php';

require_role(['student']);

$school_id  = current_school_id();
$student_id = current_student_id();

if ($student_id === 0) {
    http_response_code(403);
    die('This login is not linked to a student record. Ask your school admin to re-create your login.');
}

$stu_stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stu_stmt->execute([$student_id, $school_id]);
$student = $stu_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(403);
    die('Student record not found for this school.');
}

// --- Elections: positions currently votable that this student hasn't voted for yet ---
$votable_ballot = array_filter(
    election_approved_ballot_for_student($pdo, $school_id, $student_id),
    static fn($position) => !$position['already_voted']
);
$open_positions_to_vote = count($votable_ballot);

// --- Published marks count (teaser on the Results card) ---
$marks_count_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM student_marks sm
    JOIN assessments a ON a.id = sm.assessment_id AND a.school_id = sm.school_id
    WHERE sm.student_id = ? AND sm.school_id = ? AND a.status = 'Closed'
");
$marks_count_stmt->execute([$student_id, $school_id]);
$marks_count = (int) $marks_count_stmt->fetchColumn();

// --- Fees balance (teaser on the Fees card) ---
$fee_stmt = $pdo->prepare("
    SELECT fs.day_tuition, fs.entry_fee
    FROM fee_structures fs
    WHERE fs.school_id = ? AND fs.class_id = ?
    LIMIT 1
");
$fee_stmt->execute([$school_id, $student['class_id']]);
$fee_structure = $fee_stmt->fetch(PDO::FETCH_ASSOC);
$expected = $fee_structure ? (float) $fee_structure['day_tuition'] + (float) $fee_structure['entry_fee'] : 0.0;
$paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM fee_payments WHERE school_id = ? AND student_id = ?");
$paid_stmt->execute([$school_id, $student_id]);
$paid = (float) $paid_stmt->fetchColumn();
$balance = $expected - $paid;

// --- Attendance teaser ---
$att_stmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM attendance WHERE student_id = ? GROUP BY status");
$att_stmt->execute([$student_id]);
$attendance = ['present' => 0, 'absent' => 0, 'sick' => 0, 'permission' => 0];
foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $attendance[$row['status']] = (int) $row['c'];
}

// Donut over all 4 real statuses, not just the "present" figure the
// feature card teases.
$attendance_colors = ['present' => 'var(--green)', 'absent' => 'var(--danger)', 'sick' => 'var(--amber)', 'permission' => 'var(--purple)'];
$attendance_total = array_sum($attendance);
$attendance_gradient_stops = [];
$attendance_legend = [];
$__acursor = 0;
foreach ($attendance_colors as $status => $color) {
    $n = $attendance[$status];
    if ($n === 0) {
        continue;
    }
    $pct = round($n / $attendance_total * 100);
    $attendance_gradient_stops[] = "{$color} {$__acursor}% " . ($__acursor + $pct) . '%';
    $__acursor += $pct;
    $attendance_legend[] = ['label' => ucfirst($status), 'n' => $n, 'color' => $color];
}

// Unread teacher messages across every conversation this student is in.
$unread_msgs_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM conversation_messages cm
    JOIN conversations cv ON cv.id = cm.conversation_id
    WHERE cv.student_id = ? AND cv.school_id = ? AND cm.sender_role = 'teacher' AND cm.read_at IS NULL
");
$unread_msgs_stmt->execute([$student_id, $school_id]);
$unread_message_count = (int) $unread_msgs_stmt->fetchColumn();

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

// Plain rgba() instead of CSS color-mix() for the icon-badge tint -- some
// budget Android browsers still in use at schools don't support
// color-mix() yet, and this needs to just work everywhere.
function hex_to_tint(string $hex, float $alpha = 0.16): string
{
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    return "rgba($r, $g, $b, $alpha)";
}

// The cards themselves -- each one a full feature, not just a stat.
$cards = [
    [
        'href' => 'student_results.php', 'icon' => 'bi-mortarboard', 'color' => '#00A8A8',
        'title' => 'My Results', 'desc' => 'Published assessment marks and your report card.',
        'stat' => $marks_count . ' published', 'stat_n' => $marks_count, 'stat_suffix' => ' published',
    ],
    [
        'href' => 'student_fees.php', 'icon' => 'bi-cash-coin', 'color' => '#10b981',
        'title' => 'Fees', 'desc' => 'What you owe and what you\'ve paid so far.',
        'stat' => ($balance > 0 ? 'UGX ' . number_format($balance, 0) . ' due' : 'Fully paid'),
        'stat_class' => $balance > 0 ? 'danger' : 'green',
    ],
    [
        'href' => 'student_attendance.php', 'icon' => 'bi-calendar-check', 'color' => '#f59e0b',
        'title' => 'Attendance', 'desc' => 'Your present/absent record for the term.',
        'stat' => $attendance['present'] . ' days present', 'stat_n' => $attendance['present'], 'stat_suffix' => ' days present',
    ],
    [
        'href' => 'library/student_library.php', 'icon' => 'bi-book', 'color' => '#8b5cf6',
        'title' => 'Library', 'desc' => 'Notes and past papers shared by your teachers.',
        'stat' => null,
    ],
    [
        'href' => 'elections/ballot.php', 'icon' => 'bi-check2-square', 'color' => '#ec4899',
        'title' => 'Elections', 'desc' => 'Vote for student leadership positions.',
        'stat' => $open_positions_to_vote > 0 ? $open_positions_to_vote . ' awaiting your vote' : 'No open votes',
        'stat_class' => $open_positions_to_vote > 0 ? 'danger' : null,
    ],
    [
        'href' => 'student_messages.php', 'icon' => 'bi-chat-dots', 'color' => '#3b82f6',
        'title' => 'Messages', 'desc' => 'Talk directly with your subject teachers.',
        'stat' => $unread_message_count > 0 ? $unread_message_count . ' unread' : null,
        'stat_class' => $unread_message_count > 0 ? 'danger' : null,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Portal — <?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; --amber:#f59e0b; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:1040px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;flex-wrap:wrap;gap:12px;position:sticky;top:0;z-index:20;background:var(--bg);padding:12px 0;}
.header h1{margin:0;font-size:1.4rem;}
.header .sub{color:var(--muted);font-size:0.85rem;}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;}
.feature-card{display:block;background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;text-decoration:none;color:var(--text);transition:transform .15s,border-color .15s,box-shadow .15s;position:relative;overflow:hidden;}
.feature-card:hover{transform:translateY(-4px);border-color:var(--icon-color, var(--cyan));box-shadow:0 12px 30px rgba(0,0,0,.3);}
.feature-card .icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:16px;background:var(--icon-tint);color:var(--icon-color);}
.feature-card h3{margin:0 0 6px;font-size:1.05rem;}
.feature-card p{margin:0;color:var(--muted);font-size:0.82rem;line-height:1.5;}
.feature-card .stat{display:inline-block;margin-top:14px;font-size:0.75rem;font-weight:700;padding:4px 10px;border-radius:20px;background:rgba(148,163,184,0.15);color:var(--muted);}
.feature-card .stat.danger{background:rgba(239,68,68,0.14);color:var(--danger);}
.feature-card .stat.green{background:rgba(16,185,129,0.14);color:var(--green);}
.feature-card .arrow{position:absolute;top:22px;right:22px;color:var(--muted);font-size:1.1rem;opacity:0;transition:opacity .15s,transform .15s;}
.feature-card:hover .arrow{opacity:1;transform:translateX(3px);}
@media (max-width:480px){.header{flex-wrap:wrap;gap:10px;}}

:root{ --purple:#a855f7; }
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
                <div style="width:40px;height:40px;border-radius:6px;background:var(--cyan);color:#04222a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;"><?= htmlspecialchars(strtoupper(substr($__school_brand['school_name'] ?? 'S', 0, 1))) ?></div>
            <?php endif; ?>
            <div>
            <h1><?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="sub">
                <?= htmlspecialchars($student['class_name'] ?? 'Unassigned class', ENT_QUOTES, 'UTF-8') ?>
                · <?= htmlspecialchars($student['level_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($student['student_no'])): ?> · No. <?= htmlspecialchars($student['student_no'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            </div>
            </div>
        </div>
        <a class="scholar-logout-btn" href="logout.php">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Log Out
        </a>
    </div>

    <?php if ($attendance_total > 0): ?>
    <div class="chart-card reveal">
        <h2>My Attendance</h2>
        <div class="donut" style="background:conic-gradient(<?= implode(', ', $attendance_gradient_stops) ?>);">
            <div class="donut-center">
                <div class="n"><?= $attendance_total ?></div>
                <div class="label">Days Recorded</div>
            </div>
        </div>
        <div class="donut-legend">
            <?php foreach ($attendance_legend as $entry): ?>
                <span><span class="dot" style="background:<?= $entry['color'] ?>;"></span><?= htmlspecialchars($entry['label']) ?> <?= $entry['n'] ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

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

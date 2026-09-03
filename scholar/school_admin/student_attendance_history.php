<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — STUDENT ATTENDANCE HISTORY
|--------------------------------------------------------------------------
| One student's attendance record, two kinds: whole-class daily entries
| (school_admin/attendance.php AND teacher_attendance.php's roll call --
| both write school_id set, subject_id NULL) and older per-subject roll
| call history (subject_id set, from before teacher_attendance.php
| dropped its subject picker). Linked from student_profile.php's Quick
| Modules panel, which pointed nowhere before this page existed.
|--------------------------------------------------------------------------
*/

// Error display is governed by config.php's SCHOLAR_ENV check (loaded via
// db.php below) -- this used to force display_errors=1 unconditionally,
// leaking stack traces to any visitor regardless of SCHOLAR_ENV, unlike
// every other page in the app.
require '../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'school_admin' ||
    !isset($_SESSION['school_id'])
) {
    header("Location: ../login.php");
    exit;
}

$school_id = (int) $_SESSION['school_id'];
$student_id = (int) ($_GET['id'] ?? 0);

require_once __DIR__ . '/_student_attendance_history_helpers.php';

$history = admin_student_attendance_history_fetch($pdo, $school_id, $student_id);

if (!$history['ok']) {
    exit($history['message']);
}

$student = $history['student'];
$rows = $history['rows'];

function safe($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$status_colors = [
    'present'    => '#10b981',
    'absent'     => '#ef4444',
    'sick'       => '#f59e0b',
    'permission' => '#00A8A8',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance History — <?= safe($student['full_name']) ?></title>
<style>
:root{
    --bg:#080b11;
    --panel:#131b28;
    --border:#2a3a52;
    --text:#e2e8f0;
    --muted:#64748b;
    --cyan:#00A8A8;
}
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.wrap{max-width:820px;margin:0 auto;padding:32px 20px 60px;}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
h1{font-size:1.3rem;margin:0 0 4px;}
.sub{color:var(--muted);font-size:0.85rem;margin:0;}
a.back{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
a.back:hover{background:rgba(0,168,168,0.1);}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
th,td{padding:10px 14px;text-align:left;font-size:0.85rem;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.72rem;letter-spacing:0.05em;}
tr:last-child td{border-bottom:none;}
.pill{display:inline-block;padding:2px 10px;border-radius:100px;font-size:0.75rem;font-weight:700;color:#04121a;}
.empty{text-align:center;padding:40px 20px;color:var(--muted);}
</style>
</head>
<body>
<?php include __DIR__ . '/../preloader.php'; ?>

<div class="wrap">
    <div class="top">
        <div>
            <h1>Attendance History</h1>
            <p class="sub"><?= safe($student['full_name']) ?> &middot; <?= safe($student['class_name'] ?? '—') ?></p>
        </div>
        <a href="student_profile.php?id=<?= (int) $student_id ?>" class="back">&larr; Back to Profile</a>
    </div>

    <?php if (empty($rows)): ?>
        <div class="empty">No attendance has been recorded for this student yet.</div>
    <?php else: ?>
        <table>
            <tr><th>Date</th><th>Type</th><th>Status</th></tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= safe($r['attendance_date']) ?></td>
                    <td><?= $r['subject_id'] ? safe($r['subject_name'] ?? 'Subject') : 'Daily attendance' ?></td>
                    <td>
                        <span class="pill" style="background:<?= safe($status_colors[$r['status']] ?? '#64748b') ?>">
                            <?= safe(ucfirst($r['status'])) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

</body>
</html>

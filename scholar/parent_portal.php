<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — PARENT PORTAL
|--------------------------------------------------------------------------
| Parents can VIEW their own linked children's profile/attendance and can
| SEND feedback/questions to the school. They cannot edit any student data.
| Linking a parent account to a child is done by the school admin via
| school_admin/manage_parents.php — a parent can never see a student they
| aren't linked to (enforced in every query below via parent_students).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['parent']);

$school_id = current_school_id();
$parent_user_id = current_user_id();

$error = '';
$success = '';

// Only children actually linked to this parent account are ever visible.
$children_stmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.class_name, s.sex, s.level_type, s.photo_path
    FROM parent_students ps
    JOIN students s ON s.id = ps.student_id
    WHERE ps.user_id = ? AND ps.school_id = ?
    ORDER BY s.full_name
");
$children_stmt->execute([$parent_user_id, $school_id]);
$children = $children_stmt->fetchAll();
$child_ids = array_column($children, 'id');

// ---- Feedback submission (the only write a parent can make) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_feedback'])) {
    $student_id = (int) ($_POST['student_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!in_array($student_id, $child_ids, true)) {
        $error = 'You can only send feedback about your own linked child.';
    } elseif ($subject === '' || $message === '') {
        $error = 'Please fill in both a subject and a message.';
    } else {
        $ins = $pdo->prepare("
            INSERT INTO parent_feedback (school_id, parent_user_id, student_id, subject, message)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([$school_id, $parent_user_id, $student_id, $subject, $message]);
        $success = 'Your message has been sent to the school.';
    }
}

// Attendance summary per child (last 30 days)
$attendance_by_child = [];
if ($child_ids) {
    $placeholders = implode(',', array_fill(0, count($child_ids), '?'));
    $att = $pdo->prepare("
        SELECT student_id, status, COUNT(*) AS n
        FROM attendance
        WHERE student_id IN ($placeholders)
          AND attendance_date >= (CURDATE() - INTERVAL 30 DAY)
        GROUP BY student_id, status
    ");
    $att->execute($child_ids);
    foreach ($att->fetchAll() as $row) {
        $attendance_by_child[$row['student_id']][$row['status']] = (int) $row['n'];
    }
}

// Aggregate present/absent across all of this parent's children, for the
// donut -- attendance_by_child already has everything needed, no new query.
$attendance_present_total = 0;
$attendance_absent_total = 0;
foreach ($attendance_by_child as $child_stats) {
    $attendance_present_total += $child_stats['present'] ?? 0;
    $attendance_absent_total += $child_stats['absent'] ?? 0;
}
$attendance_grand_total = $attendance_present_total + $attendance_absent_total;
$attendance_present_pct = $attendance_grand_total > 0 ? round($attendance_present_total / $attendance_grand_total * 100) : 0;

// This parent's past feedback + any school response
$my_feedback = [];
if ($child_ids) {
    $fb = $pdo->prepare("
        SELECT subject, message, status, response, created_at
        FROM parent_feedback
        WHERE parent_user_id = ? AND school_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $fb->execute([$parent_user_id, $school_id]);
    $my_feedback = $fb->fetchAll();
}

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
<title>Parent Portal</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:900px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;position:sticky;top:0;z-index:20;background:var(--bg);padding:12px 0;}
.child-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:16px;}
.child-card h3{margin:0 0 8px;}
.meta{color:var(--muted);font-size:0.85rem;}
.pill{display:inline-block;font-size:0.75rem;padding:3px 10px;border-radius:20px;margin-right:6px;margin-top:8px;}
.pill.present{background:rgba(16,185,129,0.15);color:var(--green);}
.pill.absent{background:rgba(239,68,68,0.15);color:var(--danger);}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-top:24px;}
label{display:block;font-size:0.8rem;color:var(--muted);margin:12px 0 4px;}
select,input,textarea{width:100%;padding:10px;background:var(--panel);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:inherit;}
button{margin-top:16px;background:var(--cyan);color:#04222a;font-weight:700;border:none;padding:12px 22px;border-radius:8px;cursor:pointer;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.feedback-item{padding:12px 0;border-bottom:1px solid var(--border);font-size:0.85rem;}
.status-tag{font-size:0.7rem;text-transform:uppercase;color:var(--muted);}
.logout{color:var(--danger);text-decoration:none;font-size:0.75rem;font-weight:700;text-transform:uppercase;border:1px solid rgba(239,68,68,0.3);padding:8px 16px;border-radius:6px;}
.empty{color:var(--muted);font-size:0.85rem;}
table{display:block;overflow-x:auto;}
@media (max-width:480px){.header{flex-wrap:wrap;gap:10px;}}

.reveal{opacity:0;transform:translateY(16px);transition:opacity .5s ease, transform .5s ease;}
.reveal.revealed{opacity:1;transform:translateY(0);}
.chart-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:20px;display:flex;flex-direction:column;align-items:center;}
.chart-card h2{font-size:0.9rem;margin:0 0 18px;align-self:flex-start;}
.donut{width:140px;height:140px;border-radius:50%;margin-bottom:16px;position:relative;transform:scale(.7);opacity:0;transition:transform .6s cubic-bezier(.22,1,.36,1), opacity .6s ease;}
.reveal.revealed .donut{transform:scale(1);opacity:1;}
.donut::after{content:'';position:absolute;inset:18px;background:var(--panel);border-radius:50%;}
.donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.donut-center .n{font-size:1.3rem;font-weight:700;}
.donut-center .label{font-size:0.62rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;}
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
                <div style="width:40px;height:40px;border-radius:6px;background:var(--cyan);color:#04222a;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;flex-shrink:0;"><?= htmlspecialchars(strtoupper(substr($__school_brand['school_name'] ?? 'S', 0, 1))) ?></div>
            <?php endif; ?>
            <h1 style="margin:0;font-size:1.4rem;">Parent Portal</h1>
        </div>
        <a class="scholar-logout-btn" href="logout.php">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Log Out
        </a>
    </div>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

    <?php if (!$children): ?>
        <div class="child-card empty">No children are linked to this account yet. Please contact the school office.</div>
    <?php endif; ?>

    <?php if ($attendance_grand_total > 0): ?>
    <div class="chart-card reveal">
        <h2>Attendance (last 30 days, all children)</h2>
        <div class="donut" style="background:conic-gradient(var(--green) 0% <?= $attendance_present_pct ?>%, var(--danger) <?= $attendance_present_pct ?>% 100%);">
            <div class="donut-center">
                <div class="n"><?= $attendance_present_pct ?>%</div>
                <div class="label">Present</div>
            </div>
        </div>
        <div class="donut-legend">
            <span><span class="dot" style="background:var(--green);"></span>Present <?= $attendance_present_total ?></span>
            <span><span class="dot" style="background:var(--danger);"></span>Absent <?= $attendance_absent_total ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($children as $child): ?>
        <?php $att = $attendance_by_child[$child['id']] ?? []; ?>
        <div class="child-card reveal">
            <h3><?= htmlspecialchars($child['full_name'], ENT_QUOTES) ?></h3>
            <div class="meta"><?= htmlspecialchars($child['class_name'] ?? '—', ENT_QUOTES) ?> · <?= htmlspecialchars($child['level_type'] ?? '', ENT_QUOTES) ?></div>
            <span class="pill present">Present (30d): <span data-count="<?= (int)($att['present'] ?? 0) ?>"><?= (int)($att['present'] ?? 0) ?></span></span>
            <span class="pill absent">Absent (30d): <span data-count="<?= (int)($att['absent'] ?? 0) ?>"><?= (int)($att['absent'] ?? 0) ?></span></span>
        </div>
    <?php endforeach; ?>

    <?php if ($children): ?>
    <div class="section">
        <h2 style="font-size:1rem;margin:0;">Send a Message to the School</h2>
        <form method="post">
            <label>Regarding</label>
            <select name="student_id" required>
                <?php foreach ($children as $child): ?>
                    <option value="<?= (int)$child['id'] ?>"><?= htmlspecialchars($child['full_name'], ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Subject</label>
            <input type="text" name="subject" maxlength="150" required>
            <label>Message</label>
            <textarea name="message" rows="4" required></textarea>
            <button type="submit" name="send_feedback" value="1">Send Message</button>
        </form>
    </div>

    <div class="section">
        <h2 style="font-size:1rem;margin:0 0 10px;">Your Past Messages</h2>
        <?php if ($my_feedback): ?>
            <?php foreach ($my_feedback as $f): ?>
            <div class="feedback-item">
                <strong><?= htmlspecialchars($f['subject'], ENT_QUOTES) ?></strong>
                <span class="status-tag"> · <?= htmlspecialchars($f['status'], ENT_QUOTES) ?></span>
                <div><?= nl2br(htmlspecialchars($f['message'], ENT_QUOTES)) ?></div>
                <?php if ($f['response']): ?>
                    <div style="margin-top:6px;color:var(--green);">School reply: <?= nl2br(htmlspecialchars($f['response'], ENT_QUOTES)) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty">No messages sent yet.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<script src="assets/js/dashboard-effects.js"></script>
</body>
</html>

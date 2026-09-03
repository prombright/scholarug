<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — MY LEAVE (self-service)
|--------------------------------------------------------------------------
| Any staff-linked login can apply for leave here; HR/school_admin review
| and approve/reject in hr/leave_review.php. Every query is scoped to
| current_staff_id() from the session, never a posted id -- same
| ownership-safety convention as every other self-service page in this
| app (student_messages.php, teacher_class_logins.php, ...).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['teacher', 'dos', 'headteacher', 'bursar', 'nurse', 'hr']);

$school_id = current_school_id();
$staff_id = current_staff_id();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_leave'])) {
    $leave_type = trim($_POST['leave_type'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($leave_type === '' || $start_date === '' || $end_date === '') {
        $error = 'Please fill in leave type and both dates.';
    } elseif (strtotime($end_date) < strtotime($start_date)) {
        $error = 'End date can\'t be before the start date.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO leave_requests (school_id, staff_id, leave_type, start_date, end_date, reason)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$school_id, $staff_id, $leave_type, $start_date, $end_date, $reason]);
        $message = 'Leave request submitted.';
    }
}

$requests_stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE school_id = ? AND staff_id = ? ORDER BY applied_at DESC");
$requests_stmt->execute([$school_id, $staff_id]);
$requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);

// Best-effort "back to my portal" link -- role_destination() already maps
// every role to its dashboard, reused here so this page doesn't need its
// own role-to-URL switch.
$back_url = role_destination($_SESSION['role']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Leave — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; --amber:#f59e0b; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:800px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.3rem;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin:12px 0 6px;}
label:first-child{margin-top:0;}
input,select,textarea{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;margin-top:16px;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pill{font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.pill-pending{background:rgba(245,158,11,0.14);color:var(--amber);}
.pill-approved{background:rgba(16,185,129,0.12);color:var(--green);}
.pill-rejected{background:rgba(239,68,68,0.12);color:var(--danger);}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
</style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>
<div class="container">
    <div class="header">
        <h1>My Leave</h1>
        <a href="<?= htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-link">&larr; Back to Portal</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="section">
        <form method="POST">
            <label>Leave Type</label>
            <select name="leave_type" required>
                <option value="">-- Select --</option>
                <option value="Sick Leave">Sick Leave</option>
                <option value="Annual Leave">Annual Leave</option>
                <option value="Maternity/Paternity Leave">Maternity/Paternity Leave</option>
                <option value="Compassionate Leave">Compassionate Leave</option>
                <option value="Other">Other</option>
            </select>
            <div class="row">
                <div>
                    <label>Start Date</label>
                    <input type="date" name="start_date" required>
                </div>
                <div>
                    <label>End Date</label>
                    <input type="date" name="end_date" required>
                </div>
            </div>
            <label>Reason (optional)</label>
            <textarea name="reason" rows="3"></textarea>
            <button type="submit" name="apply_leave">Submit Request</button>
        </form>
    </div>

    <div class="section">
        <table>
            <tr><th>Type</th><th>Dates</th><th>Status</th><th>Applied</th></tr>
            <?php if (empty($requests)): ?>
                <tr><td colspan="4" class="empty">No leave requests yet.</td></tr>
            <?php else: ?>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['leave_type']) ?></td>
                        <td><?= htmlspecialchars(date('d M', strtotime($r['start_date']))) ?> &ndash; <?= htmlspecialchars(date('d M Y', strtotime($r['end_date']))) ?></td>
                        <td><span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td><?= htmlspecialchars(date('d M Y', strtotime($r['applied_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>

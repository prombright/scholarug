<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — CLASS TEACHER: MY STUDENTS' LOGINS
|--------------------------------------------------------------------------
| Read-only view of a class teacher's own students' portal usernames, plus
| their temp password for as long as it's still temp (users.temp_password_plain
| -- cleared the moment a student sets their own real password, see
| force_password_reset.php). Once that's null, a class teacher genuinely
| can't recover it (it's bcrypt-hashed) -- the only action available is
| flagging it for the school admin, who alone can regenerate
| (school_admin/students.php's "Regenerate Password" action).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_guard.php';

require_role(['teacher']);

$school_id = current_school_id();
$staff_id = current_staff_id();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_reset') {
    $student_id = (int) ($_POST['student_id'] ?? 0);

    // Ownership check: the student must belong to a class this teacher is
    // the official class teacher of -- never trust the posted student_id
    // alone.
    $stu_stmt = $pdo->prepare("SELECT id, full_name, class_id FROM students WHERE id = ? AND school_id = ?");
    $stu_stmt->execute([$student_id, $school_id]);
    $student_row = $stu_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student_row || !is_class_teacher_of($pdo, $staff_id, (int) $student_row['class_id'])) {
        $error = "You can only request a reset for your own class's students.";
    } else {
        $upd = $pdo->prepare("UPDATE users SET reset_requested = 1 WHERE student_id = ? AND school_id = ? AND role = 'student'");
        $upd->execute([$student_id, $school_id]);
        $message = "Reset requested for {$student_row['full_name']} — the school admin will be notified.";
    }
}

$classesStmt = $pdo->prepare("
    SELECT id, class_name, stream_name FROM classes
    WHERE school_id = ? AND class_teacher_id = ?
    ORDER BY FIELD(class_name, 'S.1','S.2','S.3','S.4','S.5','S.6'), stream_name
");
$classesStmt->execute([$school_id, $staff_id]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

$class_ids = array_map('intval', array_column($classes, 'id'));
$students_by_class = [];

if (!empty($class_ids)) {
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT s.id, s.full_name, s.class_id, u.username, u.temp_password_plain, u.reset_requested
        FROM students s
        LEFT JOIN users u ON u.student_id = s.id AND u.school_id = s.school_id AND u.role = 'student'
        WHERE s.school_id = ? AND s.class_id IN ($placeholders)
        ORDER BY s.full_name ASC
    ");
    $stmt->execute(array_merge([$school_id], $class_ids));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $students_by_class[(int) $row['class_id']][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Students' Logins — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; --amber:#f59e0b; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:900px;margin:auto;padding:32px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.3rem;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
a.btn-link:hover{background:rgba(0,168,168,0.1);}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert-success{background:rgba(16,185,129,0.12);color:var(--green);}
.alert-danger{background:rgba(239,68,68,0.12);color:var(--danger);}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:20px;}
.section-header{padding:16px 20px;border-bottom:1px solid var(--border);font-size:0.9rem;font-weight:700;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;display:block;overflow-x:auto;}
th,td{text-align:left;padding:12px 20px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;letter-spacing:0.5px;background:rgba(255,255,255,0.02);}
tbody tr:last-child td{border-bottom:none;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}
.code{font-family:monospace;font-weight:700;color:var(--cyan);}
.na{color:var(--muted);font-style:italic;}
.pill{display:inline-block;font-size:0.72rem;padding:3px 9px;border-radius:20px;font-weight:600;background:rgba(245,158,11,0.14);color:var(--amber);}
button{cursor:pointer;border:none;border-radius:6px;padding:6px 12px;font-weight:700;font-size:0.75rem;background:transparent;color:var(--text);border:1px solid var(--border);}
</style>
</head>
<body>
<?php include __DIR__ . '/preloader.php'; ?>
<div class="container">
    <div class="header">
        <h1>My Students' Logins</h1>
        <a href="teachers_portal.php" class="btn-link">&larr; Back to Portal</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($classes)): ?>
        <p class="empty">You're not assigned as the class teacher of any class yet.</p>
    <?php endif; ?>

    <?php foreach ($classes as $c): ?>
        <?php $class_id = (int) $c['id']; $rows = $students_by_class[$class_id] ?? []; ?>
        <div class="section">
            <div class="section-header"><?= htmlspecialchars($c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '')) ?></div>
            <?php if (empty($rows)): ?>
                <p class="empty">No students in this class yet.</p>
            <?php else: ?>
            <table>
                <tr><th>Full Name</th><th>Username</th><th>Password</th><th></th></tr>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['full_name']) ?></td>
                    <?php if (!$r['username']): ?>
                        <td colspan="3" class="na">No portal login yet — ask school admin to create one.</td>
                    <?php elseif ($r['temp_password_plain']): ?>
                        <td class="code"><?= htmlspecialchars($r['username']) ?></td>
                        <td class="code"><?= htmlspecialchars($r['temp_password_plain']) ?></td>
                        <td></td>
                    <?php else: ?>
                        <td class="code"><?= htmlspecialchars($r['username']) ?></td>
                        <td class="na">Already set by student</td>
                        <td>
                            <?php if ($r['reset_requested']): ?>
                                <span class="pill">Reset requested</span>
                            <?php else: ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Ask the school admin to reset this student\'s password?');">
                                    <input type="hidden" name="action" value="request_reset">
                                    <input type="hidden" name="student_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit">Request Reset</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>

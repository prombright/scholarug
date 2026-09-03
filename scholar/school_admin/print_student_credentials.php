<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — PRINT STUDENT LOGIN CREDENTIALS (by class)
|--------------------------------------------------------------------------
| Same "toolbar hidden via @media print + window.print()" pattern as
| bulk_report_print.php, scoped to one class at a time. Only ever shows
| users.temp_password_plain -- never re-derives a password from
| students.student_no -- so a student who already set their own password
| correctly shows as "already set", not a stale/wrong code.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';

require_role(['school_admin']);

$school_id = current_school_id();
$error = '';

$classesStmt = $pdo->prepare("
    SELECT id, class_name, stream_name FROM classes
    WHERE school_id = ?
    ORDER BY FIELD(class_name, 'S.1','S.2','S.3','S.4','S.5','S.6'), stream_name
");
$classesStmt->execute([$school_id]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

$sel_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : null;
$class_label = '';
$rows = [];

if ($sel_class !== null) {
    $allowed_ids = array_map('intval', array_column($classes, 'id'));
    if (!in_array($sel_class, $allowed_ids, true)) {
        $error = 'You do not have access to that class.';
        $sel_class = null;
    } else {
        foreach ($classes as $c) {
            if ((int) $c['id'] === $sel_class) {
                $class_label = $c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '');
            }
        }

        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, u.username, u.temp_password_plain
            FROM students s
            LEFT JOIN users u ON u.student_id = s.id AND u.school_id = s.school_id AND u.role = 'student'
            WHERE s.school_id = ? AND s.class_id = ?
            ORDER BY s.full_name ASC
        ");
        $stmt->execute([$school_id, $sel_class]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Print Student Credentials — Scholar</title>
<style>
:root{ --bg:#080b11; --panel:#131b28; --border:#2a3a52; --text:#e2e8f0; --muted:#64748b; --cyan:#00A8A8; --green:#10b981; --danger:#ef4444; }
*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.toolbar{max-width:1000px;margin:0 auto;padding:32px 20px 20px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.header h1{margin:0;font-size:1.3rem;}
a.btn-link{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:1px solid rgba(0,168,168,0.3);padding:8px 16px;border-radius:6px;}
a.btn-link:hover{background:rgba(0,168,168,0.1);}
.filters{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.filters .row{display:grid;grid-template-columns:2fr auto;gap:14px;align-items:end;}
@media(max-width:768px){ .filters .row{grid-template-columns:1fr;} }
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 18px;font-weight:700;font-size:0.85rem;background:var(--cyan);color:#04121a;}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;background:rgba(239,68,68,0.12);color:var(--danger);}
.summary{color:var(--muted);font-size:0.85rem;margin-bottom:16px;}
.empty{color:var(--muted);font-size:0.85rem;padding:16px 0;text-align:center;}

.sheet{max-width:850px;margin:0 auto 40px;background:#fff;color:#1e293b;padding:30px;border-radius:4px;font-family:'Segoe UI',Arial,sans-serif;}
.sheet h2{margin:0 0 4px;font-size:18px;}
.sheet .meta{color:#64748b;font-size:12px;margin-bottom:18px;}
.cred-table{width:100%;border-collapse:collapse;font-size:13px;}
.cred-table th{background:#0f172a;color:#fff;text-transform:uppercase;font-size:10.5px;padding:10px 8px;text-align:left;letter-spacing:0.4px;}
.cred-table td{padding:9px 8px;border:1px solid #cbd5e1;}
.cred-table tr:nth-child(even) td{background:#f8fafc;}
.code{font-family:monospace;font-weight:700;}
.na{color:#94a3b8;font-style:italic;}

@media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .sheet { padding: 0; max-width: 100%; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/../preloader.php'; ?>
<div class="toolbar">
    <div class="header">
        <h1>Print Student Credentials</h1>
        <a href="students.php" class="btn-link">&larr; Back to Students</a>
    </div>

    <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($classes)): ?>
        <p class="empty">No classes set up for this school yet.</p>
    <?php else: ?>
    <div class="filters">
        <form method="GET">
            <div class="row">
                <div>
                    <label>Class</label>
                    <select name="class_id" required>
                        <option value="">-- Choose Class --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $sel_class === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['class_name'] . ($c['stream_name'] ? ' - ' . $c['stream_name'] : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit">Load Class</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($sel_class !== null): ?>
        <?php if (!empty($rows)): ?>
            <div class="summary"><?= count($rows) ?> student(s) in <strong><?= htmlspecialchars($class_label) ?></strong>.</div>
            <button onclick="window.print();">Print</button>
        <?php else: ?>
            <p class="empty">No students found in this class.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!empty($rows)): ?>
<div class="sheet">
    <h2>Student Portal Login Credentials</h2>
    <div class="meta"><?= htmlspecialchars($class_label) ?> &middot; printed <?= date('d M Y') ?></div>
    <table class="cred-table">
        <tr>
            <th>Full Name</th>
            <th>Username</th>
            <th>Password</th>
        </tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['full_name']) ?></td>
            <?php if (!$r['username']): ?>
                <td colspan="2" class="na">No portal login yet — create one from the Students page.</td>
            <?php elseif ($r['temp_password_plain']): ?>
                <td class="code"><?= htmlspecialchars($r['username']) ?></td>
                <td class="code"><?= htmlspecialchars($r['temp_password_plain']) ?></td>
            <?php else: ?>
                <td class="code"><?= htmlspecialchars($r['username']) ?></td>
                <td class="na">Already set by student</td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

</body>
</html>

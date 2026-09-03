<?php
declare(strict_types=1);

require '../db.php';
require_once __DIR__ . '/_school_delete.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'developer' ||
    !isset($_SESSION['user_id'])
) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['developer_csrf_token'])) {
    $_SESSION['developer_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['developer_csrf_token'];

$school_id = (int) ($_GET['id'] ?? $_POST['school_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$school_id]);
$school = $stmt->fetch();

if (!$school) {
    header('Location: schools.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['developer_csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }

    $confirm_code = trim((string) ($_POST['confirm_code'] ?? ''));

    if ($confirm_code !== $school['school_code']) {
        $error = 'The school code you typed does not match. Nothing was deleted.';
    } else {
        $result = developer_delete_school($pdo, $school_id);
        if ($result['ok']) {
            $_SESSION['schools_flash'] = 'Deleted "' . $result['school_name'] . '" (' . $result['school_code'] . ') and all of its data.';
            header('Location: schools.php');
            exit;
        }
        $error = $result['error'] ?? 'Something went wrong. Nothing was deleted.';
    }
}

// Counts shown to the developer so "and its data" isn't an abstract promise.
$counts = [];
foreach ([
    'Students' => 'students',
    'Staff' => 'staff',
    'Classes' => 'classes',
    'Login accounts' => 'users',
    'Fee records' => 'fees',
    'Attendance records' => 'attendance',
    'Library documents' => 'library_documents',
] as $label => $table) {
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE school_id = ?");
        $c->execute([$school_id]);
        $counts[$label] = (int) $c->fetchColumn();
    } catch (\Throwable $e) {
        $counts[$label] = 0;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>ScholarUg | Delete School</title>
<style>
body{margin:0;background:#080b11;font-family:Inter,"Segoe UI",sans-serif;color:#e2e8f0;}
.container{max-width:640px;margin:60px auto;padding:0 20px;}
a.back{color:#06b6d4;text-decoration:none;font-size:0.85rem;}
.card{background:#0d1118;border:1px solid #1e293b;border-radius:12px;padding:30px;margin-top:20px;}
h1{color:#fca5a5;font-size:1.3rem;margin:0 0 6px;}
.school-name{color:white;font-weight:700;font-size:1.05rem;margin:0 0 4px;}
.school-code{color:#64748b;font-family:monospace;margin:0 0 20px;}
.warning{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);border-radius:8px;padding:14px 16px;font-size:0.85rem;color:#fca5a5;margin-bottom:20px;line-height:1.6;}
.counts{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:24px;}
.count-item{background:#080b11;border:1px solid #1e293b;border-radius:8px;padding:12px 14px;}
.count-item .n{font-size:1.3rem;font-weight:800;color:white;}
.count-item .l{font-size:0.7rem;color:#64748b;text-transform:uppercase;}
label{display:block;font-size:0.75rem;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;}
input.confirm{width:100%;box-sizing:border-box;padding:12px 14px;background:#080b11;border:1px solid #1e293b;color:white;border-radius:7px;font-family:monospace;font-size:1rem;margin-bottom:20px;}
.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;padding:12px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:18px;}
.btn-row{display:flex;gap:10px;}
button,.btn-cancel{padding:12px 20px;border-radius:7px;border:none;font-weight:700;font-size:0.85rem;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}
.btn-delete{background:#ef4444;color:white;}
.btn-cancel{background:#111827;border:1px solid #1e293b;color:#94a3b8;}
</style>
</head>
<body>
<div class="container">
<a class="back" href="schools.php">&larr; Back to Schools</a>

<div class="card">
    <h1>Delete School</h1>
    <div class="school-name"><?= htmlspecialchars($school['school_name'], ENT_QUOTES, 'UTF-8') ?></div>
    <div class="school-code"><?= htmlspecialchars($school['school_code'], ENT_QUOTES, 'UTF-8') ?></div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="warning">
        This permanently deletes this school and every record that belongs to it — students, staff,
        classes, marks, attendance, fees, messages, library documents, everything. No other school is
        affected. <strong>This cannot be undone.</strong>
    </div>

    <div class="counts">
        <?php foreach ($counts as $label => $n): ?>
        <div class="count-item">
            <div class="n"><?= number_format($n) ?></div>
            <div class="l"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="school_id" value="<?= $school_id ?>">
        <label>Type the school code (<?= htmlspecialchars($school['school_code'], ENT_QUOTES, 'UTF-8') ?>) to confirm</label>
        <input type="text" name="confirm_code" class="confirm" autocomplete="off" required>
        <div class="btn-row">
            <button type="submit" class="btn-delete">Permanently Delete School</button>
            <a href="schools.php" class="btn-cancel">Cancel</a>
        </div>
    </form>
</div>
</div>
</body>
</html>

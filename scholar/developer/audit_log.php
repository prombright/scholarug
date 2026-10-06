<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVELOPER — AUDIT LOG
|--------------------------------------------------------------------------
| Reads audit_logs, written to by scholar_audit_log() (see ../_audit_log.php)
| from every sensitive delete/role-change/money/grade action wired up so
| far: staff delete, staff role changes, fee structure edits, fee payments,
| class deletes, student password resets, and marks submission. The table
| itself predates this feature and already held a handful of school-delete
| rows from the developer's own _school_delete.php-adjacent tooling.
|--------------------------------------------------------------------------
*/

require '../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'developer' ||
    !isset($_SESSION['user_id'])
) {
    header('Location: login.php');
    exit;
}

$table_missing = false;
$totals = ['all' => 0, 'today' => 0, 'week' => 0];
$actions_list = [];
$schools_list = [];
$rows = [];

$filter_action = trim($_GET['action'] ?? '');
$filter_school = isset($_GET['school_id']) && $_GET['school_id'] !== '' ? (int) $_GET['school_id'] : null;
$filter_q = trim($_GET['q'] ?? '');

try {
    $totals['all'] = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $totals['today'] = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $totals['week'] = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE created_at >= NOW() - INTERVAL 7 DAY')->fetchColumn();

    $actions_list = $pdo->query('SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

    $schools_list = $pdo->query("
        SELECT DISTINCT s.id, s.school_name
        FROM audit_logs al
        JOIN schools s ON s.id = al.school_id
        ORDER BY s.school_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $sql = "
        SELECT al.id, al.school_id, al.user_id, al.action, al.table_name, al.record_id, al.description, al.created_at,
               s.school_name, u.username, u.role AS user_role
        FROM audit_logs al
        LEFT JOIN schools s ON s.id = al.school_id
        LEFT JOIN users u ON u.id = al.user_id
        WHERE 1 = 1
    ";
    $params = [];
    if ($filter_action !== '') {
        $sql .= ' AND al.action = ?';
        $params[] = $filter_action;
    }
    if ($filter_school !== null) {
        $sql .= ' AND al.school_id = ?';
        $params[] = $filter_school;
    }
    if ($filter_q !== '') {
        $sql .= ' AND al.description LIKE ?';
        $params[] = '%' . $filter_q . '%';
    }
    $sql .= ' ORDER BY al.created_at DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if ((int) $e->errorInfo[1] === 1146) {
        $table_missing = true;
    } else {
        throw $e;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
Audit Log | ScholarUg Developer
</title>

<style>

:root {
    --bg: #080b11;
    --panel: #0d1118;
    --border: #1e293b;
    --text: #e2e8f0;
    --muted: #64748b;
    --green: #10b981;
    --cyan: #06b6d4;
    --amber: #f59e0b;
    --red: #ef4444;
}

*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:1200px;margin:auto;padding:30px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px;}
h1{margin:0;font-size:1.3rem;}
a.back{color:var(--cyan);text-decoration:none;font-size:0.85rem;}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 24px;line-height:1.5;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.85rem;}
.alert.warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);color:#f59e0b;}
.filename{font-family:monospace;}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:18px 20px;}
.stat-card .n{font-size:1.7rem;font-weight:800;color:var(--green);}
.stat-card .l{font-size:0.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}

.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:14px 16px;}
.filters select, .filters input{background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:6px;padding:8px 10px;font-size:0.82rem;font-family:inherit;}
.filters input{flex:1;min-width:160px;}
.filters button{background:var(--green);color:#06281d;border:none;border-radius:6px;padding:8px 16px;font-size:0.82rem;font-weight:700;cursor:pointer;}
.filters a.clear{color:var(--muted);font-size:0.8rem;text-decoration:none;align-self:center;}

.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:24px;}
.section-header{padding:14px 20px;border-bottom:1px solid var(--border);font-size:0.9rem;font-weight:700;display:flex;justify-content:space-between;align-items:center;}
.section-header span.count{color:var(--muted);font-weight:400;font-size:0.78rem;}

table{width:100%;border-collapse:collapse;font-size:0.82rem;}
th,td{text-align:left;padding:11px 20px;border-bottom:1px solid var(--border);vertical-align:top;}
th{color:var(--muted);text-transform:uppercase;font-size:0.65rem;font-weight:700;letter-spacing:0.5px;}
tr:last-child td{border-bottom:none;}
.when{color:var(--muted);white-space:nowrap;font-size:0.78rem;}
.pill{display:inline-block;font-size:0.65rem;padding:3px 9px;border-radius:20px;font-weight:700;white-space:nowrap;}
.pill.delete{background:rgba(239,68,68,.12);color:var(--red);}
.pill.change{background:rgba(245,158,11,.12);color:var(--amber);}
.pill.other{background:rgba(6,182,212,.12);color:var(--cyan);}
.actor{white-space:nowrap;}
.actor .role{color:var(--muted);font-size:0.72rem;display:block;}
.desc{color:var(--text);max-width:420px;}
.empty{color:var(--muted);font-size:0.85rem;padding:30px;text-align:center;}

</style>

</head>

<body>

<?php include __DIR__ . '/../preloader.php'; ?>

<div class="container">

<div class="header">
    <h1>Audit Log</h1>
    <a class="back" href="developer_dashboard.php">&larr; Dashboard</a>
</div>

<p class="sub">
    Who did what: staff deletions, role changes, fee structure/payment edits, class deletions,
    student password resets, and marks submissions. Logged by <span class="filename">scholar_audit_log()</span>
    right after each action succeeds.
</p>

<?php if ($table_missing): ?>
    <div class="alert warn">
        The <span class="filename">audit_logs</span> table doesn't exist on this database yet.
        It's part of the base schema -- if it's genuinely missing, create it via phpMyAdmin before
        reloading this page. Nothing will be logged until it exists.
    </div>
<?php else: ?>

<div class="stat-grid">
    <div class="stat-card"><div class="n"><?= number_format($totals['all']) ?></div><div class="l">All-Time Entries</div></div>
    <div class="stat-card"><div class="n"><?= number_format($totals['today']) ?></div><div class="l">Today</div></div>
    <div class="stat-card"><div class="n"><?= number_format($totals['week']) ?></div><div class="l">Last 7 Days</div></div>
</div>

<form class="filters" method="get">
    <select name="action">
        <option value="">All actions</option>
        <?php foreach ($actions_list as $a): ?>
            <option value="<?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_action === $a ? 'selected' : '' ?>>
                <?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="school_id">
        <option value="">All schools</option>
        <?php foreach ($schools_list as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $filter_school === (int) $s['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['school_name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="q" placeholder="Search description..." value="<?= htmlspecialchars($filter_q, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">Filter</button>
    <?php if ($filter_action !== '' || $filter_school !== null || $filter_q !== ''): ?>
        <a class="clear" href="audit_log.php">Clear</a>
    <?php endif; ?>
</form>

<div class="section">
    <div class="section-header">
        Recent Activity
        <span class="count"><?= count($rows) ?> shown<?= count($rows) === 200 ? ' (latest 200)' : '' ?></span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty">No matching audit entries yet.</div>
    <?php else: ?>
    <table>
        <tr>
            <th>When</th>
            <th>School</th>
            <th>Actor</th>
            <th>Action</th>
            <th>What</th>
        </tr>
        <?php foreach ($rows as $r):
            $pillClass = str_contains($r['action'] ?? '', 'delete') ? 'delete'
                : (str_contains($r['action'] ?? '', 'change') || str_contains($r['action'] ?? '', 'reset') ? 'change' : 'other');
        ?>
            <tr>
                <td class="when"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($r['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $r['school_name'] ? htmlspecialchars($r['school_name'], ENT_QUOTES, 'UTF-8') : '<span style="color:var(--muted)">—</span>' ?></td>
                <td class="actor">
                    <?= $r['username'] ? htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') : '<span style="color:var(--muted)">Unknown</span>' ?>
                    <?php if ($r['user_role']): ?><span class="role"><?= htmlspecialchars($r['user_role'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                </td>
                <td><span class="pill <?= $pillClass ?>"><?= htmlspecialchars($r['action'] ?? 'unknown', ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="desc"><?= htmlspecialchars($r['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>

</div>

</body>

</html>

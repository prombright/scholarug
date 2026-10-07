<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVELOPER — LOGIN ATTEMPTS
|--------------------------------------------------------------------------
| Reads login_attempts (see _setup/login_lockout_migration.sql +
| login_attempts_ip_migration.sql), written by login_record_attempt() in
| auth_guard.php -- now actually called from login.php itself (previously
| only developer/login.php called it, despite auth_guard.php's own comment
| claiming otherwise; the main public login page had no rate-limiting or
| attempt logging at all until this was wired up alongside this page).
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
$totals = ['failed_today' => 0, 'failed_week' => 0, 'locked_out_now' => 0, 'unique_failed_ips' => 0];
$top_ips = [];
$rows = [];

$filter_result = trim($_GET['result'] ?? ''); // '', 'failed', 'succeeded'
$filter_q = trim($_GET['q'] ?? ''); // identifier or IP substring

try {
    $totals['failed_today'] = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND DATE(created_at) = CURDATE()")->fetchColumn();
    $totals['failed_week'] = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();

    $totals['locked_out_now'] = (int) $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT identifier FROM login_attempts
            WHERE succeeded = 0 AND created_at > NOW() - INTERVAL 15 MINUTE
            GROUP BY identifier HAVING COUNT(*) >= 5
        ) locked
    ")->fetchColumn();

    $totals['unique_failed_ips'] = (int) $pdo->query("
        SELECT COUNT(DISTINCT ip_address) FROM login_attempts
        WHERE succeeded = 0 AND ip_address IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY
    ")->fetchColumn();

    $top_ips_stmt = $pdo->query("
        SELECT ip_address, COUNT(*) AS cnt, COUNT(DISTINCT identifier) AS identifiers_tried, MAX(created_at) AS last_seen
        FROM login_attempts
        WHERE succeeded = 0 AND ip_address IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY
        GROUP BY ip_address
        ORDER BY cnt DESC
        LIMIT 10
    ");
    $top_ips = $top_ips_stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT id, identifier, succeeded, ip_address, created_at FROM login_attempts WHERE 1 = 1";
    $params = [];
    if ($filter_result === 'failed') {
        $sql .= ' AND succeeded = 0';
    } elseif ($filter_result === 'succeeded') {
        $sql .= ' AND succeeded = 1';
    }
    if ($filter_q !== '') {
        $sql .= ' AND (identifier LIKE ? OR ip_address LIKE ?)';
        $params[] = '%' . $filter_q . '%';
        $params[] = '%' . $filter_q . '%';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 200';

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
Login Attempts | ScholarUg Developer
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

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:18px 20px;}
.stat-card .n{font-size:1.7rem;font-weight:800;color:var(--green);}
.stat-card.warn .n{color:var(--amber);}
.stat-card.danger .n{color:var(--red);}
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
th,td{text-align:left;padding:11px 20px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.65rem;font-weight:700;letter-spacing:0.5px;}
tr:last-child td{border-bottom:none;}
.when{color:var(--muted);white-space:nowrap;font-size:0.78rem;}
.ip{font-family:monospace;color:var(--text);}
.pill{display:inline-block;font-size:0.65rem;padding:3px 9px;border-radius:20px;font-weight:700;white-space:nowrap;}
.pill.fail{background:rgba(239,68,68,.12);color:var(--red);}
.pill.ok{background:rgba(16,185,129,.12);color:var(--green);}
.cnt{color:var(--muted);text-align:right;}
.empty{color:var(--muted);font-size:0.85rem;padding:20px;text-align:center;}

</style>

</head>

<body>

<?php include __DIR__ . '/../preloader.php'; ?>

<div class="container">

<div class="header">
    <h1>Login Attempts</h1>
    <a class="back" href="developer_dashboard.php">&larr; Dashboard</a>
</div>

<p class="sub">
    Every login attempt across the school-code+PIN and username/password paths (students excepted --
    see <span class="filename">login_lockout_migration.sql</span>), including the originating IP.
    5 failed attempts on the same identifier within 15 minutes locks it out.
</p>

<?php if ($table_missing): ?>
    <div class="alert warn">
        The <span class="filename">login_attempts</span> table doesn't exist on this database yet.
        Apply <span class="filename">_setup/login_lockout_migration.sql</span> and
        <span class="filename">_setup/login_attempts_ip_migration.sql</span> first
        (via <span class="filename">_run_pending_migrations.php</span>), then reload this page.
    </div>
<?php else: ?>

<div class="stat-grid">
    <div class="stat-card danger"><div class="n"><?= number_format($totals['failed_today']) ?></div><div class="l">Failed Today</div></div>
    <div class="stat-card danger"><div class="n"><?= number_format($totals['failed_week']) ?></div><div class="l">Failed (7 Days)</div></div>
    <div class="stat-card warn"><div class="n"><?= number_format($totals['locked_out_now']) ?></div><div class="l">Locked Out Right Now</div></div>
    <div class="stat-card"><div class="n"><?= number_format($totals['unique_failed_ips']) ?></div><div class="l">Unique IPs w/ Failures (7d)</div></div>
</div>

<div class="section">
    <div class="section-header">Most Active Failing IPs — Last 7 Days</div>
    <?php if (!$top_ips): ?>
        <div class="empty">No failed attempts with a recorded IP in the last 7 days.</div>
    <?php else: ?>
    <table>
        <tr><th>IP Address</th><th>Failed Attempts</th><th>Identifiers Tried</th><th>Last Seen</th></tr>
        <?php foreach ($top_ips as $ip): ?>
            <tr>
                <td class="ip"><?= htmlspecialchars($ip['ip_address'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="cnt"><?= number_format((int) $ip['cnt']) ?></td>
                <td class="cnt"><?= number_format((int) $ip['identifiers_tried']) ?></td>
                <td class="when"><?= htmlspecialchars(date('M j, g:ia', strtotime($ip['last_seen'])), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<form class="filters" method="get">
    <select name="result">
        <option value="">All results</option>
        <option value="failed" <?= $filter_result === 'failed' ? 'selected' : '' ?>>Failed only</option>
        <option value="succeeded" <?= $filter_result === 'succeeded' ? 'selected' : '' ?>>Succeeded only</option>
    </select>
    <input type="text" name="q" placeholder="Search identifier or IP..." value="<?= htmlspecialchars($filter_q, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">Filter</button>
    <?php if ($filter_result !== '' || $filter_q !== ''): ?>
        <a class="clear" href="login_attempts.php">Clear</a>
    <?php endif; ?>
</form>

<div class="section">
    <div class="section-header">
        Recent Attempts
        <span class="count"><?= count($rows) ?> shown<?= count($rows) === 200 ? ' (latest 200)' : '' ?></span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty">No matching login attempts yet.</div>
    <?php else: ?>
    <table>
        <tr>
            <th>When</th>
            <th>Identifier</th>
            <th>IP Address</th>
            <th>Result</th>
        </tr>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="when"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($r['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['identifier'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="ip"><?= $r['ip_address'] ? htmlspecialchars($r['ip_address'], ENT_QUOTES, 'UTF-8') : '<span style="color:var(--muted)">—</span>' ?></td>
                <td><span class="pill <?= $r['succeeded'] ? 'ok' : 'fail' ?>"><?= $r['succeeded'] ? 'Succeeded' : 'Failed' ?></span></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>

</div>

</body>

</html>

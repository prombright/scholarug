<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVELOPER — SITE & APP ANALYTICS
|--------------------------------------------------------------------------
| Reads page_views (see _setup/page_views_migration.sql and
| ../_visit_tracking.php) -- one row per page load, written by
| preloader.php (system='scholar', every Scholar app page) and
| includes/track_visit.php (system='site', every marketing site page).
|
| Replaces the old scholar_track_visit() in preloader.php, which wrote
| into a separate abn_platform database that was never actually
| provisioned on this hosting account -- every call silently failed, so
| there was never any visit data to show here in the first place.
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
$totals = ['all' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
$by_system = [];
$top_pages = [];
$top_schools = [];
$daily = []; // 'Y-m-d' => count, last 14 days

try {
    $totals['all'] = (int) $pdo->query('SELECT COUNT(*) FROM page_views')->fetchColumn();
    $totals['today'] = (int) $pdo->query('SELECT COUNT(*) FROM page_views WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $totals['week'] = (int) $pdo->query('SELECT COUNT(*) FROM page_views WHERE created_at >= NOW() - INTERVAL 7 DAY')->fetchColumn();
    $totals['month'] = (int) $pdo->query('SELECT COUNT(*) FROM page_views WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetchColumn();

    $sys_stmt = $pdo->query("
        SELECT system, COUNT(*) AS cnt
        FROM page_views
        WHERE created_at >= NOW() - INTERVAL 30 DAY
        GROUP BY system
    ");
    foreach ($sys_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $by_system[$row['system']] = (int) $row['cnt'];
    }

    $pages_stmt = $pdo->query("
        SELECT path, system, COUNT(*) AS cnt
        FROM page_views
        WHERE created_at >= NOW() - INTERVAL 30 DAY
        GROUP BY path, system
        ORDER BY cnt DESC
        LIMIT 15
    ");
    $top_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);

    $schools_stmt = $pdo->query("
        SELECT pv.school_id, s.school_name, COUNT(*) AS cnt
        FROM page_views pv
        JOIN schools s ON s.id = pv.school_id
        WHERE pv.system = 'scholar' AND pv.school_id IS NOT NULL AND pv.created_at >= NOW() - INTERVAL 30 DAY
        GROUP BY pv.school_id, s.school_name
        ORDER BY cnt DESC
        LIMIT 10
    ");
    $top_schools = $schools_stmt->fetchAll(PDO::FETCH_ASSOC);

    $daily_stmt = $pdo->query("
        SELECT DATE(created_at) AS d, COUNT(*) AS cnt
        FROM page_views
        WHERE created_at >= CURDATE() - INTERVAL 13 DAY
        GROUP BY DATE(created_at)
    ");
    $raw_daily = [];
    foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raw_daily[$row['d']] = (int) $row['cnt'];
    }
    // Fill in every day of the window, even ones with zero views, so the
    // bar chart below has a consistent 14-day x-axis instead of silently
    // compressing past a quiet day.
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $daily[$d] = $raw_daily[$d] ?? 0;
    }
} catch (PDOException $e) {
    if ((int) $e->errorInfo[1] === 1146) {
        $table_missing = true;
    } else {
        throw $e;
    }
}

$max_daily = $daily ? max($daily) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
Analytics | ScholarUg Developer
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
}

*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:1100px;margin:auto;padding:30px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px;}
h1{margin:0;font-size:1.3rem;}
a.back{color:var(--cyan);text-decoration:none;font-size:0.85rem;}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 24px;line-height:1.5;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.85rem;}
.alert.warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);color:#f59e0b;}
.filename{font-family:monospace;}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px;}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:18px 20px;}
.stat-card .n{font-size:1.7rem;font-weight:800;color:var(--green);}
.stat-card .l{font-size:0.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-top:4px;}

.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:24px;}
.section-header{padding:14px 20px;border-bottom:1px solid var(--border);font-size:0.9rem;font-weight:700;}

.chart{display:flex;align-items:flex-end;gap:6px;height:140px;padding:20px;}
.chart-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end;}
.chart-bar{width:100%;max-width:28px;background:var(--green);border-radius:3px 3px 0 0;min-height:2px;}
.chart-n{font-size:0.65rem;color:var(--muted);}
.chart-d{font-size:0.62rem;color:var(--muted);writing-mode:vertical-rl;text-orientation:mixed;}

table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:11px 20px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.68rem;font-weight:700;letter-spacing:0.5px;}
tr:last-child td{border-bottom:none;}
.path{font-family:monospace;color:var(--text);}
.pill{display:inline-block;font-size:0.68rem;padding:3px 9px;border-radius:20px;font-weight:700;}
.pill.scholar{background:rgba(6,182,212,.12);color:var(--cyan);}
.pill.site{background:rgba(16,185,129,.12);color:var(--green);}
.cnt{color:var(--muted);text-align:right;}
.empty{color:var(--muted);font-size:0.85rem;padding:20px;text-align:center;}

</style>

</head>

<body>

<?php include __DIR__ . '/../preloader.php'; ?>

<div class="container">

<div class="header">
    <h1>Site &amp; App Analytics</h1>
    <a class="back" href="developer_dashboard.php">&larr; Dashboard</a>
</div>

<p class="sub">
    Every page load across both the marketing site and the Scholar app, logged into
    <span class="filename">page_views</span>. "Scholar" is the app itself (school_admin/teacher/student/parent
    portals); "Site" is this marketing site.
</p>

<?php if ($table_missing): ?>
    <div class="alert warn">
        The <span class="filename">page_views</span> table doesn't exist on this database yet.
        Apply <span class="filename">_setup/page_views_migration.sql</span> first
        (via <span class="filename">_run_pending_migrations.php</span>, or a manual phpMyAdmin/mysql import),
        then reload this page. Nothing will be tracked until it's applied either.
    </div>
<?php else: ?>

<div class="stat-grid">
    <div class="stat-card"><div class="n"><?= number_format($totals['all']) ?></div><div class="l">All-Time Views</div></div>
    <div class="stat-card"><div class="n"><?= number_format($totals['today']) ?></div><div class="l">Today</div></div>
    <div class="stat-card"><div class="n"><?= number_format($totals['week']) ?></div><div class="l">Last 7 Days</div></div>
    <div class="stat-card"><div class="n"><?= number_format($totals['month']) ?></div><div class="l">Last 30 Days</div></div>
    <div class="stat-card"><div class="n"><?= number_format($by_system['scholar'] ?? 0) ?></div><div class="l">Scholar App (30d)</div></div>
    <div class="stat-card"><div class="n"><?= number_format($by_system['site'] ?? 0) ?></div><div class="l">Marketing Site (30d)</div></div>
</div>

<div class="section">
    <div class="section-header">Views Per Day — Last 14 Days</div>
    <div class="chart">
        <?php foreach ($daily as $d => $cnt): ?>
            <div class="chart-col">
                <div class="chart-n"><?= $cnt > 0 ? $cnt : '' ?></div>
                <div class="chart-bar" style="height:<?= $max_daily > 0 ? max(2, round($cnt / $max_daily * 100)) : 2 ?>px;"></div>
                <div class="chart-d"><?= date('M j', strtotime($d)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="section">
    <div class="section-header">Top Pages — Last 30 Days</div>
    <?php if (!$top_pages): ?>
        <div class="empty">No views recorded yet.</div>
    <?php else: ?>
    <table>
        <tr><th>Page</th><th>System</th><th style="text-align:right;">Views</th></tr>
        <?php foreach ($top_pages as $p): ?>
            <tr>
                <td class="path"><?= htmlspecialchars($p['path'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="pill <?= $p['system'] === 'scholar' ? 'scholar' : 'site' ?>"><?= $p['system'] === 'scholar' ? 'Scholar' : 'Site' ?></span></td>
                <td class="cnt"><?= number_format((int) $p['cnt']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="section">
    <div class="section-header">Most Active Schools — Last 30 Days</div>
    <?php if (!$top_schools): ?>
        <div class="empty">No school activity recorded yet.</div>
    <?php else: ?>
    <table>
        <tr><th>School</th><th style="text-align:right;">Views</th></tr>
        <?php foreach ($top_schools as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['school_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="cnt"><?= number_format((int) $s['cnt']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>

</div>

</body>

</html>

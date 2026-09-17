<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVELOPER — SCHEMA MIGRATIONS STATUS
|--------------------------------------------------------------------------
| Cross-references every _setup/*.sql file against the schema_migrations
| table (see _setup/schema_migrations_tracking.sql) so "has this been
| applied to THIS database?" is a fact on screen instead of something that
| has to be diagnosed by hand (which is exactly what took reports down when
| grading_scales_level_type_migration.sql went unapplied on a live
| database with no way to tell).
|
| This page does not run migrations itself -- developer/_run_pending_
| migrations.php (or a manual phpMyAdmin/mysql import) is still how a
| migration actually gets applied. "Mark as Applied" here only records
| that fact; it never touches the schema. Only mark a file applied once
| you've actually confirmed it (ran it yourself, or verified the
| table/column it adds already exists) -- a false "applied" here is worse
| than an honest "not tracked yet", since it would hide a real gap.
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

$msg = '';
$msg_type = 'info';

// _setup/*.sql filenames are also used as CSRF-free "which row" identifiers
// in the POST handlers below -- validated against this same on-disk list
// (not accepted as free-form input) so a submitted filename can never name
// anything outside this exact folder.
$setup_dir = realpath(__DIR__ . '/../_setup');
$sql_files = array_map('basename', glob($setup_dir . '/*.sql') ?: []);
sort($sql_files);

$tracking_table_missing = false;
$applied = []; // filename => applied_at

try {
    $stmt = $pdo->query('SELECT filename, applied_at FROM schema_migrations');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $applied[$row['filename']] = $row['applied_at'];
    }
} catch (PDOException $e) {
    // 1146 = table doesn't exist -- schema_migrations_tracking.sql itself
    // hasn't been applied yet on this database. Every other page on this
    // site treats an unapplied migration as a hard failure; this page is
    // the one place that has to tolerate it, since it's the page that
    // exists to surface exactly that situation.
    if ((int) $e->errorInfo[1] === 1146) {
        $tracking_table_missing = true;
    } else {
        throw $e;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$tracking_table_missing) {
    $filename = basename((string) ($_POST['filename'] ?? ''));

    if (!in_array($filename, $sql_files, true)) {
        $msg = 'Unknown migration file.';
        $msg_type = 'error';
    } elseif (($_POST['action'] ?? '') === 'mark_applied') {
        $pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')->execute([$filename]);
        $msg = "Marked {$filename} as applied.";
        $applied[$filename] = date('Y-m-d H:i:s');
    } elseif (($_POST['action'] ?? '') === 'unmark') {
        $pdo->prepare('DELETE FROM schema_migrations WHERE filename = ?')->execute([$filename]);
        $msg = "Un-marked {$filename} -- it will show as not tracked again.";
        unset($applied[$filename]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>
Migrations Status | ScholarUg Developer
</title>

<style>

:root {
    --bg: #080b11;
    --panel: #0d1118;
    --border: #1e293b;
    --text: #e2e8f0;
    --muted: #64748b;
}

*{box-sizing:border-box;}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",sans-serif;}
.container{max-width:1000px;margin:auto;padding:30px;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px;}
h1{margin:0;font-size:1.3rem;}
a.back{color:#06b6d4;text-decoration:none;font-size:0.85rem;}
.sub{color:var(--muted);font-size:0.85rem;margin:0 0 24px;line-height:1.5;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171;}
.alert.info{background:rgba(6,182,212,.1);border:1px solid rgba(6,182,212,.3);color:#06b6d4;}
.alert.warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);color:#f59e0b;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
th,td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;font-weight:700;}
tr:last-child td{border-bottom:none;}
.filename{font-family:monospace;color:var(--text);}
.pill{display:inline-block;font-size:0.72rem;padding:4px 10px;border-radius:20px;font-weight:700;}
.pill.applied{background:rgba(16,185,129,.1);color:#6ee7b7;}
.pill.pending{background:rgba(245,158,11,.1);color:#f59e0b;}
.applied-at{color:var(--muted);font-size:0.78rem;}
button{background:transparent;border:1px solid var(--border);color:var(--text);padding:7px 14px;border-radius:6px;font-size:0.78rem;font-weight:600;cursor:pointer;}
button.mark{border-color:rgba(16,185,129,.4);color:#6ee7b7;}
button.mark:hover{background:rgba(16,185,129,.1);}
button.unmark{border-color:rgba(239,68,68,.3);color:#f87171;}
button.unmark:hover{background:rgba(239,68,68,.1);}

</style>

</head>

<body>

<?php include __DIR__ . '/../preloader.php'; ?>

<div class="container">

<div class="header">
    <h1>Schema Migrations Status</h1>
    <a class="back" href="developer_dashboard.php">&larr; Dashboard</a>
</div>

<p class="sub">
    Every <span class="filename">_setup/*.sql</span> file, cross-referenced against this database's own
    <span class="filename">schema_migrations</span> record. "Mark as Applied" only records that a file has
    been run -- it never runs anything itself. Only mark a file applied once you've actually confirmed it,
    either by running it yourself or by verifying the table/column it adds already exists.
</p>

<?php if ($msg): ?>
    <div class="alert <?= $msg_type ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($tracking_table_missing): ?>
    <div class="alert warn">
        The <span class="filename">schema_migrations</span> table doesn't exist on this database yet, so
        nothing below can be tracked. Apply <span class="filename">_setup/schema_migrations_tracking.sql</span>
        first (phpMyAdmin import, or <span class="filename">mysql -u root scholar &lt; schema_migrations_tracking.sql</span>),
        then reload this page.
    </div>
<?php else: ?>

<table>
    <tr><th>File</th><th>Status</th><th>Applied At</th><th></th></tr>
    <?php foreach ($sql_files as $f): $is_applied = isset($applied[$f]); ?>
        <tr>
            <td class="filename"><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></td>
            <td>
                <?php if ($is_applied): ?>
                    <span class="pill applied">Applied</span>
                <?php else: ?>
                    <span class="pill pending">Not tracked</span>
                <?php endif; ?>
            </td>
            <td class="applied-at"><?= $is_applied ? htmlspecialchars((string) $applied[$f], ENT_QUOTES, 'UTF-8') : '—' ?></td>
            <td style="text-align:right;white-space:nowrap;">
                <?php if ($is_applied): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Un-mark <?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>? This does not undo the migration itself, only the tracking record.');">
                        <input type="hidden" name="filename" value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="unmark">
                        <button type="submit" class="unmark">Un-mark</button>
                    </form>
                <?php else: ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Confirm <?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?> has actually been applied to this database?');">
                        <input type="hidden" name="filename" value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="mark_applied">
                        <button type="submit" class="mark">Mark as Applied</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<?php endif; ?>

</div>

</body>

</html>

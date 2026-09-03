<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: POSITIONS (admin-only)
|--------------------------------------------------------------------------
| Positions (Head Prefect, Class Monitor, etc.) within one election.
| Editable only while the election hasn't started its voting phase yet --
| changing the ballot's shape mid-vote would be unfair/confusing.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['school_admin']);
require_once __DIR__ . '/_election_helpers.php';
require_once __DIR__ . '/_admin_pages_helpers.php';

$school_id = current_school_id();
$election_id = (int) ($_GET['election_id'] ?? $_POST['election_id'] ?? 0);
$error = '';
$success = '';

$election = admin_election_resolve($pdo, $school_id, $election_id);

if (!$election) {
    header('Location: index.php?err=notfound');
    exit;
}

$locked = admin_election_locked($election, $pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_position'])) {
    if ($locked) {
        $error = 'Positions can no longer be changed once voting has started.';
    } else {
        $result = admin_election_position_add($pdo, $election_id, trim($_POST['title'] ?? ''));
        if ($result['ok']) { $success = $result['message']; } else { $error = $result['message']; }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_position'])) {
    if ($locked) {
        $error = 'Positions can no longer be changed once voting has started.';
    } else {
        $result = admin_election_position_delete($pdo, $election_id, (int) ($_POST['position_id'] ?? 0));
        $success = $result['message'];
    }
}

$positions = admin_election_positions_list($pdo, $election_id);

$ACTIVE_NAV = 'elections';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
:root{--green:#10b981; --amber:#f59e0b;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:#10b981;}
.disclaimer{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:var(--amber);margin-bottom:20px;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
.row{display:flex;gap:14px;align-items:end;margin-bottom:14px;}
.row > div:first-child{flex:1;}
button{background:var(--cyan);color:#04121a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
button.danger{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:6px 12px;font-size:0.78rem;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
a.act{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:600;}
a.back{color:var(--muted);text-decoration:none;font-size:0.8rem;}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
</style>
<main class="main-content">
<div class="page-inner">
    <p><a href="index.php" class="back">&larr; All Elections</a></p>
    <h1 style="font-size:1.4rem;">Positions — <?= htmlspecialchars($election['title'], ENT_QUOTES) ?></h1>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

    <?php if ($locked): ?>
        <div class="disclaimer">Voting has started (or ended) for this election — positions are locked and can no longer be added or removed.</div>
    <?php endif; ?>

    <?php if (!$locked): ?>
    <div class="section">
        <form method="post" class="row">
            <input type="hidden" name="election_id" value="<?= (int) $election_id ?>">
            <div>
                <label>New Position</label>
                <input type="text" name="title" placeholder="e.g. Head Prefect" required>
            </div>
            <button type="submit" name="add_position">Add Position</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="section">
        <table>
            <thead><tr><th>Position</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($positions)): ?>
                    <tr><td colspan="2" class="empty">No positions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($positions as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['title'], ENT_QUOTES) ?></td>
                        <td>
                            <a class="act" href="candidates.php?election_id=<?= (int) $election_id ?>#position-<?= (int) $p['id'] ?>">Candidates</a>
                            <?php if (!$locked): ?>
                                <form method="post" style="display:inline;margin-left:12px;" onsubmit="return confirm('Remove this position and all its applications?');">
                                    <input type="hidden" name="election_id" value="<?= (int) $election_id ?>">
                                    <input type="hidden" name="position_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" name="delete_position" class="danger">Remove</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: LIST / CREATE (admin-only)
|--------------------------------------------------------------------------
| Create an election in Draft, set its nomination-vs-voting schedule via
| opens_at/closes_at, then Publish it (one-way: Draft -> Published).
| Everything after that -- nominations open, voting open, results revealed
| -- is derived live from those two timestamps via election_phase(), not
| any further manual status change. See _setup/elections.sql.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['school_admin']);
require_once __DIR__ . '/_election_helpers.php';
require_once __DIR__ . '/_index_helpers.php';

$school_id = current_school_id();
$staff_id = current_staff_id();
$error = '';
$success = '';

if (($_GET['err'] ?? '') === 'notfound') {
    $error = 'That election could not be found — it may have been removed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_election'])) {
    $result = admin_elections_create(
        $pdo, $school_id, $staff_id,
        trim($_POST['title'] ?? ''), trim($_POST['term'] ?? current_term()), trim($_POST['year'] ?? current_year()),
        trim($_POST['opens_at'] ?? ''), trim($_POST['closes_at'] ?? '')
    );
    if ($result['ok']) { $success = $result['message']; } else { $error = $result['message']; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_election'])) {
    admin_elections_publish($pdo, $school_id, (int) ($_POST['election_id'] ?? 0));
    $success = 'Election published. Students can now apply for its positions.';
}

$elections = admin_elections_fetch_list($pdo, $school_id);

$ACTIVE_NAV = 'elections';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
:root{--green:#10b981; --amber:#f59e0b;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:#10b981;}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
input,select{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
.row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:14px;}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
button{background:var(--cyan);color:#04121a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;}
table{width:100%;border-collapse:collapse;font-size:0.85rem;}
th,td{text-align:left;padding:10px;border-bottom:1px solid var(--border);}
th{color:var(--muted);text-transform:uppercase;font-size:0.7rem;}
.pill{display:inline-block;font-size:0.7rem;padding:3px 10px;border-radius:20px;font-weight:700;text-transform:uppercase;}
.pill.draft{background:rgba(100,116,139,0.15);color:var(--muted);}
.pill.nominating{background:rgba(245,158,11,0.15);color:var(--amber);}
.pill.voting{background:rgba(16,185,129,0.15);color:var(--green);}
.pill.closed{background:rgba(239,68,68,0.1);color:var(--danger);}
a.act{color:var(--cyan);text-decoration:none;font-size:0.8rem;font-weight:600;margin-right:12px;}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
</style>
<main class="main-content">
<div class="page-inner">
    <h1 style="font-size:1.4rem;">Student Elections</h1>
    <p style="color:var(--muted);font-size:0.85rem;margin-top:-8px;">Students self-nominate for a position; you review and approve candidacies, then voting runs automatically inside the window you set.</p>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

    <div class="section">
        <h3 style="margin-top:0;font-size:1rem;">New Election</h3>
        <form method="post">
            <div class="row3">
                <div>
                    <label>Title</label>
                    <input type="text" name="title" placeholder="e.g. 2026 Student Leadership Elections" required>
                </div>
                <div>
                    <label>Term</label>
                    <input type="text" name="term" value="<?= htmlspecialchars(current_term(), ENT_QUOTES) ?>" required>
                </div>
                <div>
                    <label>Year</label>
                    <input type="text" name="year" value="<?= htmlspecialchars(current_year(), ENT_QUOTES) ?>" required>
                </div>
            </div>
            <div class="row2">
                <div>
                    <label>Nominations Open Until / Voting Opens</label>
                    <input type="datetime-local" name="opens_at" required>
                </div>
                <div>
                    <label>Voting Closes</label>
                    <input type="datetime-local" name="closes_at" required>
                </div>
            </div>
            <div class="hint" style="color:var(--muted);font-size:0.78rem;margin-bottom:14px;">Students can apply for positions any time before "opens" — voting itself only runs between these two times, then closes automatically.</div>
            <button type="submit" name="create_election">Create Election (Draft)</button>
        </form>
    </div>

    <div class="section">
        <table>
            <thead><tr><th>Title</th><th>Term</th><th>Status</th><th>Opens</th><th>Closes</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($elections)): ?>
                    <tr><td colspan="6" class="empty">No elections yet — create one above.</td></tr>
                <?php endif; ?>
                <?php foreach ($elections as $e): ?>
                    <?php $phase = $e['phase']; ?>
                    <tr>
                        <td><?= htmlspecialchars($e['title'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($e['term'] . ' ' . $e['year'], ENT_QUOTES) ?></td>
                        <td><span class="pill <?= $phase ?>"><?= ucfirst($phase) ?></span></td>
                        <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($e['opens_at'])), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($e['closes_at'])), ENT_QUOTES) ?></td>
                        <td>
                            <a class="act" href="positions.php?election_id=<?= (int) $e['id'] ?>">Positions</a>
                            <a class="act" href="candidates.php?election_id=<?= (int) $e['id'] ?>">Candidates</a>
                            <a class="act" href="results.php?election_id=<?= (int) $e['id'] ?>">Results</a>
                            <?php if ($e['status'] === 'Draft'): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="election_id" value="<?= (int) $e['id'] ?>">
                                    <button type="submit" name="publish_election" style="padding:4px 10px;font-size:0.75rem;">Publish</button>
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

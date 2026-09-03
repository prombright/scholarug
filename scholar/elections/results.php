<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: RESULTS / LIVE TURNOUT (admin-only)
|--------------------------------------------------------------------------
| Always-live -- admins can monitor turnout and tallies at any phase, not
| just after closing. See _setup/elections.sql for why this can never show
| WHO voted for WHOM, only aggregate counts.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['school_admin']);
require_once __DIR__ . '/_election_helpers.php';

$school_id = current_school_id();
$election_id = (int) ($_GET['election_id'] ?? 0);

$elec_stmt = $pdo->prepare('SELECT * FROM elections WHERE id = ? AND school_id = ?');
$elec_stmt->execute([$election_id, $school_id]);
$election = $elec_stmt->fetch(PDO::FETCH_ASSOC);

if (!$election) {
    header('Location: index.php?err=notfound');
    exit;
}

$phase = election_phase($election, $pdo);

$pos_stmt = $pdo->prepare('SELECT * FROM election_positions WHERE election_id = ? ORDER BY display_order, title');
$pos_stmt->execute([$election_id]);
$positions = $pos_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($positions as &$p) {
    $p['turnout'] = election_turnout_for_position($pdo, (int) $p['id'], $school_id);
    $p['tally'] = election_tally_for_position($pdo, (int) $p['id']);
}
unset($p);

$ACTIVE_NAV = 'elections';
require_once __DIR__ . '/../_admin_shell.php';
?>
<style>
:root{--green:#10b981; --amber:#f59e0b;}
.section{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.disclaimer{background:rgba(0,168,168,0.08);border:1px solid rgba(0,168,168,0.35);border-radius:8px;padding:14px 16px;font-size:0.82rem;color:var(--cyan);margin-bottom:20px;line-height:1.5;}
h3{font-size:1rem;margin:0 0 4px;}
.turnout{color:var(--muted);font-size:0.8rem;margin-bottom:14px;}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.bar-name{width:160px;flex-shrink:0;font-size:0.85rem;}
.bar-bg{flex:1;background:var(--border);border-radius:6px;overflow:hidden;height:20px;position:relative;}
.bar-fill{height:100%;background:var(--cyan);}
.bar-stat{width:110px;flex-shrink:0;text-align:right;font-size:0.8rem;color:var(--muted);}
a.back{color:var(--muted);text-decoration:none;font-size:0.8rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:12px 0;}
</style>
<main class="main-content">
<div class="page-inner">
    <p><a href="index.php" class="back">&larr; All Elections</a></p>
    <h1 style="font-size:1.4rem;">Results — <?= htmlspecialchars($election['title'], ENT_QUOTES) ?></h1>

    <div class="disclaimer">
        Turnout is tracked completely separately from votes — this page (and this schema) can
        never show who voted for whom, only how many students have voted and how the tally
        stands. Current phase: <strong><?= ucfirst($phase) ?></strong>.
    </div>

    <?php if (empty($positions)): ?>
        <div class="section"><div class="empty">No positions yet.</div></div>
    <?php endif; ?>

    <?php foreach ($positions as $p): ?>
        <div class="section">
            <h3><?= htmlspecialchars($p['title'], ENT_QUOTES) ?></h3>
            <div class="turnout"><?= $p['turnout']['voted'] ?> of <?= $p['turnout']['eligible'] ?> eligible students have voted (<?= $p['turnout']['percentage'] ?>%)</div>

            <?php if (empty($p['tally']['candidates'])): ?>
                <div class="empty">No approved candidates for this position yet.</div>
            <?php else: ?>
                <?php foreach ($p['tally']['candidates'] as $c): ?>
                    <div class="bar-row">
                        <div class="bar-name"><?= htmlspecialchars($c['candidate_name'], ENT_QUOTES) ?></div>
                        <div class="bar-bg"><div class="bar-fill" style="width:<?= $c['percentage'] ?>%;"></div></div>
                        <div class="bar-stat"><?= $c['votes'] ?> votes (<?= $c['percentage'] ?>%)</div>
                    </div>
                <?php endforeach; ?>
                <div style="color:var(--muted);font-size:0.78rem;margin-top:8px;">Total votes cast for this position: <?= $p['tally']['total_votes'] ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>

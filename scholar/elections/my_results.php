<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: PAST RESULTS (student-facing)
|--------------------------------------------------------------------------
| Only elections whose voting window has actually closed -- results stay
| hidden from students during nominations/voting to avoid bandwagon
| effects, matching the same status='Closed' precedent already trusted
| for assessments/report cards elsewhere in Scholar.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_election_helpers.php';

$school_id = current_school_id();
$student_id = current_student_id();

$elec_stmt = $pdo->prepare("SELECT * FROM elections WHERE school_id = ? AND status = 'Published' ORDER BY closes_at DESC");
$elec_stmt->execute([$school_id]);

$closed_elections = [];
foreach ($elec_stmt->fetchAll(PDO::FETCH_ASSOC) as $election) {
    if (election_phase($election, $pdo) !== 'closed') {
        continue;
    }
    $pos_stmt = $pdo->prepare('SELECT * FROM election_positions WHERE election_id = ? ORDER BY display_order, title');
    $pos_stmt->execute([$election['id']]);
    $positions = $pos_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($positions as &$p) {
        $p['tally'] = election_tally_for_position($pdo, (int) $p['id']);
    }
    unset($p);
    $election['positions'] = $positions;
    $closed_elections[] = $election;
}

$votable_ballot = array_filter(
    election_approved_ballot_for_student($pdo, $school_id, $student_id),
    static fn($position) => !$position['already_voted']
);
$open_positions_to_vote = count($votable_ballot);

$stu_stmt = $pdo->prepare('SELECT full_name FROM students WHERE id = ? AND school_id = ?');
$stu_stmt->execute([$student_id, $school_id]);
$student = $stu_stmt->fetch(PDO::FETCH_ASSOC);

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$ACTIVE_NAV = 'elections';
require_once __DIR__ . '/../_student_shell.php';
?>
<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:16px;}
h3{font-size:1rem;margin:16px 0 4px;}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.bar-name{width:150px;flex-shrink:0;font-size:0.85rem;}
.bar-bg{flex:1;background:var(--border);border-radius:6px;overflow:hidden;height:18px;}
.bar-fill{height:100%;background:var(--cyan);}
.bar-stat{width:100px;flex-shrink:0;text-align:right;font-size:0.78rem;color:var(--muted);}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
</style>
<div class="page-title">Election Results</div>
<div style="margin-bottom:16px;">
    <a href="ballot.php" style="color:var(--cyan);font-size:0.8rem;margin-right:16px;">Cast Your Vote &rarr;</a>
    <a href="my_applications.php" style="color:var(--cyan);font-size:0.8rem;">My Candidacy Status &rarr;</a>
</div>

    <?php if (empty($closed_elections)): ?>
        <div class="card"><div class="empty">No elections have closed yet.</div></div>
    <?php endif; ?>

    <?php foreach ($closed_elections as $election): ?>
        <div class="card">
            <div style="font-weight:700;font-size:1rem;"><?= htmlspecialchars($election['title'], ENT_QUOTES) ?></div>
            <div style="color:var(--muted);font-size:0.8rem;">Closed <?= htmlspecialchars(date('d M Y', strtotime($election['closes_at'])), ENT_QUOTES) ?></div>

            <?php foreach ($election['positions'] as $p): ?>
                <h3><?= htmlspecialchars($p['title'], ENT_QUOTES) ?></h3>
                <?php if (empty($p['tally']['candidates'])): ?>
                    <div class="empty">No approved candidates for this position.</div>
                <?php else: ?>
                    <?php foreach ($p['tally']['candidates'] as $c): ?>
                        <div class="bar-row">
                            <div class="bar-name"><?= htmlspecialchars($c['candidate_name'], ENT_QUOTES) ?></div>
                            <div class="bar-bg"><div class="bar-fill" style="width:<?= $c['percentage'] ?>%;"></div></div>
                            <div class="bar-stat"><?= $c['votes'] ?> (<?= $c['percentage'] ?>%)</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>

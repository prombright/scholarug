<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: CANDIDATE REVIEW QUEUE (admin-only)
|--------------------------------------------------------------------------
| Students add themselves as candidates via elections/apply.php (landing
| as Pending) -- this page is where the admin records the real-world
| committee's vetting decision: Approve or Reject. Only Approved candidates
| ever appear on a student's ballot (election_approved_ballot_for_student()
| in _election_helpers.php filters on status='Approved').
|
| Approve/Reject is locked once voting has started -- changing who's on
| the ballot mid-vote would mean different voters saw different candidate
| sets for the same position, which isn't a fair election.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['school_admin']);
require_once __DIR__ . '/_election_helpers.php';
require_once __DIR__ . '/_admin_pages_helpers.php';

$school_id = current_school_id();
$staff_id = current_staff_id();
$election_id = (int) ($_GET['election_id'] ?? $_POST['election_id'] ?? 0);
$error = '';
$success = '';

$election = admin_election_resolve($pdo, $school_id, $election_id);

if (!$election) {
    header('Location: index.php?err=notfound');
    exit;
}

$locked = admin_election_locked($election, $pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_candidate']) && !$locked) {
    $result = admin_election_candidate_review(
        $pdo, $school_id, $election_id, $staff_id,
        (int) ($_POST['candidate_id'] ?? 0),
        $_POST['decision'] ?? ''
    );
    if ($result['ok']) {
        $success = $result['message'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_candidate']) && $locked) {
    $error = 'Candidates can no longer be approved or rejected once voting has started.';
}

$positions = admin_election_candidates_by_position($pdo, $election_id);

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
h3{font-size:1rem;margin:0 0 12px;}
.cand-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:24px;}
.cand-card{background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:14px;}
.cand-card img{width:100%;height:120px;object-fit:cover;border-radius:6px;margin-bottom:8px;background:var(--border);}
.cand-name{font-weight:600;font-size:0.9rem;}
.cand-manifesto{color:var(--muted);font-size:0.78rem;margin:6px 0;max-height:60px;overflow-y:auto;}
.pill{display:inline-block;font-size:0.68rem;padding:2px 8px;border-radius:20px;font-weight:700;text-transform:uppercase;margin-bottom:8px;}
.pill.Pending{background:rgba(245,158,11,0.15);color:var(--amber);}
.pill.Approved{background:rgba(16,185,129,0.15);color:var(--green);}
.pill.Rejected{background:rgba(239,68,68,0.1);color:var(--danger);}
.cand-actions{display:flex;gap:6px;margin-top:8px;}
button.approve{background:var(--green);color:#04221a;border:none;padding:6px 12px;border-radius:6px;font-size:0.75rem;font-weight:700;cursor:pointer;}
button.reject{background:transparent;color:var(--danger);border:1px solid rgba(239,68,68,0.4);padding:6px 12px;border-radius:6px;font-size:0.75rem;font-weight:700;cursor:pointer;}
a.back{color:var(--muted);text-decoration:none;font-size:0.8rem;}
.empty{color:var(--muted);font-size:0.85rem;padding:12px 0;}
</style>
<main class="main-content">
<div class="page-inner">
    <p><a href="index.php" class="back">&larr; All Elections</a></p>
    <h1 style="font-size:1.4rem;">Candidates — <?= htmlspecialchars($election['title'], ENT_QUOTES) ?></h1>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

    <?php if ($locked): ?>
        <div class="disclaimer">Voting has started (or ended) — approvals are locked so every voter sees the same candidate list.</div>
    <?php endif; ?>

    <?php if (empty($positions)): ?>
        <div class="section"><div class="empty">No positions yet — add some on the <a href="positions.php?election_id=<?= (int) $election_id ?>" style="color:var(--cyan);">Positions</a> page first.</div></div>
    <?php endif; ?>

    <?php foreach ($positions as $p): ?>
        <?php $candidates = $p['candidates']; ?>
        <div class="section" id="position-<?= (int) $p['id'] ?>">
            <h3><?= htmlspecialchars($p['title'], ENT_QUOTES) ?></h3>
            <?php if (empty($candidates)): ?>
                <div class="empty">No applications yet for this position.</div>
            <?php else: ?>
                <div class="cand-grid">
                    <?php foreach ($candidates as $c): ?>
                        <div class="cand-card">
                            <?php if ($c['photo_path']): ?>
                                <img src="../<?= htmlspecialchars($c['photo_path'], ENT_QUOTES) ?>" alt="">
                            <?php else: ?>
                                <div style="width:100%;height:120px;border-radius:6px;margin-bottom:8px;background:var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:0.75rem;">No Photo</div>
                            <?php endif; ?>
                            <div class="pill <?= $c['status'] ?>"><?= htmlspecialchars($c['status'], ENT_QUOTES) ?></div>
                            <div class="cand-name"><?= htmlspecialchars($c['candidate_name'], ENT_QUOTES) ?></div>
                            <?php if ($c['manifesto']): ?><div class="cand-manifesto"><?= nl2br(htmlspecialchars($c['manifesto'], ENT_QUOTES)) ?></div><?php endif; ?>
                            <?php if ($c['status'] === 'Pending' && !$locked): ?>
                                <div class="cand-actions">
                                    <form method="post"><input type="hidden" name="election_id" value="<?= (int) $election_id ?>"><input type="hidden" name="candidate_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="decision" value="Approved"><button type="submit" name="review_candidate" class="approve">Approve</button></form>
                                    <form method="post"><input type="hidden" name="election_id" value="<?= (int) $election_id ?>"><input type="hidden" name="candidate_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="decision" value="Rejected"><button type="submit" name="review_candidate" class="reject">Reject</button></form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</main>
</div><!-- /.app-shell -->
</body>
</html>

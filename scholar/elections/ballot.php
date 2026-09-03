<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: BALLOT (student-facing)
|--------------------------------------------------------------------------
| Shows every position currently in its voting phase, across every
| Published election, with only Approved candidates. A student's vote for
| each position is cast independently (election_cast_vote() in
| _election_helpers.php) -- submitting several positions at once still
| writes them one at a time, in SHUFFLED order, so position-adjacency in
| the submission can't be read as a timing signal (see _setup/elections.sql
| for the full anonymity design + honest caveat).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_election_helpers.php';

$school_id = current_school_id();
$student_id = current_student_id();

$notices = []; // position_id => ['ok' => bool, 'label' => string, 'message' => string]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cast_ballot'])) {
    $submitted = [];
    foreach ($_POST as $key => $value) {
        if (str_starts_with($key, 'vote_') && $value !== '') {
            $submitted[(int) substr($key, 5)] = (int) $value;
        }
    }
    $position_ids = array_keys($submitted);
    shuffle($position_ids); // anonymity mitigation -- see header comment

    foreach ($position_ids as $position_id) {
        $result = election_cast_vote($pdo, $school_id, $position_id, $submitted[$position_id], $student_id);
        $notices[$position_id] = $result;
    }
}

$ballot = election_approved_ballot_for_student($pdo, $school_id, $student_id);

$open_positions_to_vote = count(array_filter($ballot, static fn($p) => !$p['already_voted']));

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
.alert{padding:10px 14px;border-radius:8px;margin-bottom:10px;font-size:0.82rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
.cand-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:12px 0;}
.cand{background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:12px;cursor:pointer;}
.cand:has(input:checked){border-color:var(--cyan);background:rgba(0,168,168,0.08);}
.cand img{width:100%;height:100px;object-fit:cover;border-radius:6px;margin-bottom:6px;}
.cand-noimg{width:100%;height:100px;border-radius:6px;margin-bottom:6px;background:var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:0.72rem;}
.cand-name{font-weight:600;font-size:0.85rem;}
.cand label{display:flex;align-items:flex-start;gap:8px;cursor:pointer;}
.already{color:var(--muted);font-size:0.85rem;background:var(--panel);border:1px solid var(--border);border-radius:6px;padding:12px;}
button{background:var(--cyan);color:#04121a;font-weight:700;border:none;padding:12px 26px;border-radius:8px;cursor:pointer;font-size:0.9rem;margin-top:10px;}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
</style>
<div class="page-title">Cast Your Vote</div>
<div style="margin-bottom:16px;">
    <a href="apply.php" style="color:var(--cyan);font-size:0.8rem;margin-right:16px;">Apply to Run &rarr;</a>
    <a href="my_applications.php" style="color:var(--cyan);font-size:0.8rem;margin-right:16px;">My Candidacy Status &rarr;</a>
    <a href="my_results.php" style="color:var(--cyan);font-size:0.8rem;">Past Results &rarr;</a>
</div>

    <?php foreach ($notices as $position_id => $n): ?>
        <?php if ($n['ok']): ?>
            <div class="alert success">Vote recorded.</div>
        <?php elseif ($n['reason'] === 'already_voted'): ?>
            <div class="alert error">You'd already voted for that position — your original vote stands.</div>
        <?php else: ?>
            <div class="alert error">That vote couldn't be recorded (voting may have just closed). Please refresh.</div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (empty($ballot)): ?>
        <div class="card"><div class="empty">No positions are open for voting right now.</div></div>
    <?php else: ?>
    <form method="post">
        <?php foreach ($ballot as $position): ?>
            <div class="card">
                <div style="font-weight:700;font-size:0.95rem;"><?= htmlspecialchars($position['title'], ENT_QUOTES) ?></div>
                <div style="color:var(--muted);font-size:0.8rem;"><?= htmlspecialchars($position['election_title'], ENT_QUOTES) ?></div>

                <?php if ($position['already_voted']): ?>
                    <div class="already" style="margin-top:12px;">You've already voted for this position.</div>
                <?php elseif (empty($position['candidates'])): ?>
                    <div class="already" style="margin-top:12px;">No approved candidates for this position.</div>
                <?php else: ?>
                    <div class="cand-grid">
                        <?php foreach ($position['candidates'] as $c): ?>
                            <div class="cand">
                                <label>
                                    <input type="radio" name="vote_<?= (int) $position['id'] ?>" value="<?= (int) $c['id'] ?>" required style="margin-top:4px;">
                                    <div style="flex:1;">
                                        <?php if ($c['photo_path']): ?>
                                            <img src="../<?= htmlspecialchars($c['photo_path'], ENT_QUOTES) ?>" alt="">
                                        <?php else: ?>
                                            <div class="cand-noimg">No Photo</div>
                                        <?php endif; ?>
                                        <div class="cand-name"><?= htmlspecialchars($c['candidate_name'], ENT_QUOTES) ?></div>
                                        <?php if ($c['manifesto']): ?><div style="color:var(--muted);font-size:0.75rem;margin-top:4px;"><?= nl2br(htmlspecialchars($c['manifesto'], ENT_QUOTES)) ?></div><?php endif; ?>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" name="cast_ballot">Submit My Vote(s)</button>
    </form>
    <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

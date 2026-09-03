<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: APPLY TO RUN (student-facing)
|--------------------------------------------------------------------------
| Self-nomination. Every application lands as Pending -- a school admin
| records the committee's decision on elections/candidates.php later; see
| my_applications.php for where the student finds out the result.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);
require_once __DIR__ . '/_election_helpers.php';

$school_id = current_school_id();
$student_id = current_student_id();

$stu_stmt = $pdo->prepare('SELECT full_name FROM students WHERE id = ? AND school_id = ?');
$stu_stmt->execute([$student_id, $school_id]);
$student_name = $stu_stmt->fetchColumn();

if (!$student_name) {
    http_response_code(403);
    die('Student record not found for this school.');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_application'])) {
    $position_id = (int) ($_POST['position_id'] ?? 0);
    $manifesto = trim($_POST['manifesto'] ?? '');
    $photo = $_FILES['photo'] ?? null;

    $result = election_submit_application($pdo, $school_id, $position_id, $student_id, (string) $student_name, $manifesto, $photo);

    if ($result['ok']) {
        $success = 'Application submitted! You can check its status any time on "My Candidacy Status".';
    } elseif ($result['reason'] === 'already_applied') {
        $error = 'You have already applied for this position.';
    } else {
        $error = 'Applications are not currently open for that position.';
    }
}

$elec_stmt = $pdo->prepare("SELECT * FROM elections WHERE school_id = ? AND status = 'Published' ORDER BY opens_at");
$elec_stmt->execute([$school_id]);

$open_positions = [];
foreach ($elec_stmt->fetchAll(PDO::FETCH_ASSOC) as $election) {
    if (election_phase($election, $pdo) !== 'nominating') {
        continue;
    }
    $pos_stmt = $pdo->prepare('SELECT * FROM election_positions WHERE election_id = ? ORDER BY display_order, title');
    $pos_stmt->execute([$election['id']]);
    foreach ($pos_stmt->fetchAll(PDO::FETCH_ASSOC) as $position) {
        $position['election_title'] = $election['title'];
        $position['already_applied'] = election_student_application($pdo, (int) $position['id'], $student_id) !== null;
        $open_positions[] = $position;
    }
}

$votable_ballot = array_filter(
    election_approved_ballot_for_student($pdo, $school_id, $student_id),
    static fn($position) => !$position['already_voted']
);
$open_positions_to_vote = count($votable_ballot);

$__school_brand = $pdo->prepare("SELECT school_name, school_badge FROM schools WHERE id = ?");
$__school_brand->execute([$school_id]);
$__school_brand = $__school_brand->fetch() ?: [];
$__badge_url = null;
if (!empty($__school_brand['school_badge']) && file_exists(__DIR__ . '/../' . $__school_brand['school_badge'])) {
    $__badge_url = rtrim(SCHOLAR_BASE, '/') . '/' . ltrim($__school_brand['school_badge'], '/');
}

$student = ['full_name' => $student_name];
$ACTIVE_NAV = 'elections';
require_once __DIR__ . '/../_student_shell.php';
?>
<style>
.page-title{font-size:1.2rem;font-weight:700;margin:0 0 18px;}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:16px;}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;}
.alert.error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:var(--danger);}
.alert.success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--green);}
label{display:block;font-size:0.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;}
textarea,input{width:100%;background:var(--panel);border:1px solid var(--border);color:var(--text);padding:9px 10px;border-radius:6px;font-size:0.85rem;font-family:inherit;box-sizing:border-box;}
textarea{min-height:90px;resize:vertical;}
button{background:var(--cyan);color:#04121a;font-weight:700;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:0.85rem;margin-top:10px;}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
.already{color:var(--muted);font-size:0.85rem;background:var(--panel);border:1px solid var(--border);border-radius:6px;padding:12px;}
</style>
<div class="page-title">Apply to Run for a Position</div>
<div style="margin-bottom:16px;">
    <a href="ballot.php" style="color:var(--cyan);font-size:0.8rem;margin-right:16px;">Cast Your Vote &rarr;</a>
    <a href="my_applications.php" style="color:var(--cyan);font-size:0.8rem;margin-right:16px;">My Candidacy Status &rarr;</a>
    <a href="my_results.php" style="color:var(--cyan);font-size:0.8rem;">Past Results &rarr;</a>
</div>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div><?php endif; ?>

    <?php if (empty($open_positions)): ?>
        <div class="card"><div class="empty">No positions are currently open for applications.</div></div>
    <?php endif; ?>

    <?php foreach ($open_positions as $p): ?>
        <div class="card">
            <div style="font-weight:700;font-size:0.95rem;"><?= htmlspecialchars($p['title'], ENT_QUOTES) ?></div>
            <div style="color:var(--muted);font-size:0.8rem;margin-bottom:12px;"><?= htmlspecialchars($p['election_title'], ENT_QUOTES) ?></div>

            <?php if ($p['already_applied']): ?>
                <div class="already">You've already applied for this position — check "My Candidacy Status" on your dashboard for the outcome.</div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="position_id" value="<?= (int) $p['id'] ?>">
                    <label>Why should students vote for you? (optional)</label>
                    <textarea name="manifesto" placeholder="A short statement about what you'd do in this role..."></textarea>
                    <label style="margin-top:10px;">Photo (optional)</label>
                    <input type="file" name="photo" accept="image/*">
                    <button type="submit" name="submit_application">Submit Application</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>

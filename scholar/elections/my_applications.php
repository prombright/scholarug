<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: MY CANDIDACY STATUS (student-facing)
|--------------------------------------------------------------------------
| Where a student finds out the committee's decision on any position they
| applied for -- Pending while awaiting review, Approved (on the ballot),
| or Rejected, each with a plain-language note.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_guard.php';
require_role(['student']);

$school_id = current_school_id();
$student_id = current_student_id();

$stmt = $pdo->prepare('
    SELECT c.status, c.reviewed_at, p.title AS position_title, e.title AS election_title
    FROM election_candidates c
    JOIN election_positions p ON p.id = c.position_id
    JOIN elections e ON e.id = p.election_id
    WHERE c.student_id = ? AND e.school_id = ?
    ORDER BY c.created_at DESC
');
$stmt->execute([$student_id, $school_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_copy = [
    'Pending' => 'Awaiting committee review.',
    'Approved' => "You're on the ballot! Ask your classmates to vote.",
    'Rejected' => 'Not selected this time.',
];

require_once __DIR__ . '/_election_helpers.php';
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
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:14px;}
.pill{display:inline-block;font-size:0.7rem;padding:3px 10px;border-radius:20px;font-weight:700;text-transform:uppercase;margin-bottom:8px;}
.pill.Pending{background:rgba(245,158,11,0.15);color:var(--amber);}
.pill.Approved{background:rgba(16,185,129,0.15);color:var(--green);}
.pill.Rejected{background:rgba(239,68,68,0.1);color:var(--danger);}
.empty{color:var(--muted);font-size:0.85rem;text-align:center;padding:30px 0;}
</style>
<div class="page-title">My Candidacy Status</div>
<div style="margin-bottom:16px;">
    <a href="ballot.php" style="color:var(--cyan);font-size:0.8rem;margin-right:16px;">Cast Your Vote &rarr;</a>
    <a href="my_results.php" style="color:var(--cyan);font-size:0.8rem;">Past Results &rarr;</a>
</div>

    <?php if (empty($applications)): ?>
        <div class="card"><div class="empty">You haven't applied for any positions yet. <a href="apply.php" style="color:var(--cyan);">Apply now &rarr;</a></div></div>
    <?php endif; ?>

    <?php foreach ($applications as $a): ?>
        <div class="card">
            <div class="pill <?= $a['status'] ?>"><?= htmlspecialchars($a['status'], ENT_QUOTES) ?></div>
            <div style="font-weight:700;font-size:0.95rem;"><?= htmlspecialchars($a['position_title'], ENT_QUOTES) ?></div>
            <div style="color:var(--muted);font-size:0.8rem;margin-bottom:8px;"><?= htmlspecialchars($a['election_title'], ENT_QUOTES) ?></div>
            <div style="font-size:0.85rem;"><?= htmlspecialchars($status_copy[$a['status']] ?? '', ENT_QUOTES) ?></div>
        </div>
    <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>

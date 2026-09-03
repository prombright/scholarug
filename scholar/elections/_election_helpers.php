<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ELECTIONS: SHARED HELPERS
|--------------------------------------------------------------------------
| Included by every elections/*.php page after auth_guard.php + db.php.
| See _setup/elections.sql for the anonymity design (why election_votes and
| election_ballots_cast are two separate tables) and the honest caveat
| about what "anonymous" does and doesn't guarantee here.
|--------------------------------------------------------------------------
*/

/**
 * Single source of truth for "what phase is this election in right now" --
 * every page branches on this instead of re-deriving it, so the
 * Draft/nominating/voting/closed logic never drifts out of sync across
 * pages. Draft is never visible to students regardless of dates.
 *
 * Deliberately compares plain MySQL DATETIME strings ('Y-m-d H:i:s' is
 * zero-padded and fixed-width, so lexicographic string comparison is also
 * chronological comparison) instead of parsing them through PHP's
 * DateTime/timezone machinery -- PHP's date.timezone (php.ini) and MySQL's
 * own system timezone are two independently-configured settings that are
 * NOT guaranteed to match (confirmed live on this box: PHP defaults to
 * Europe/Berlin, MySQL's SYSTEM timezone reads an hour ahead), and
 * opens_at/closes_at were themselves stored as literal wall-clock strings
 * with no timezone info at all (straight from a <input type="datetime-local">
 * form field). Asking MySQL itself for NOW() and staying in "plain string"
 * land the whole way through is what keeps this consistent with whatever
 * clock actually wrote those columns.
 */
function election_phase(array $election, PDO $pdo): string
{
    if ($election['status'] !== 'Published') {
        return 'draft';
    }
    $now = $pdo->query('SELECT NOW()')->fetchColumn();
    if ($now < $election['opens_at']) return 'nominating';
    if ($now <= $election['closes_at']) return 'voting';
    return 'closed';
}

function election_student_has_voted(PDO $pdo, int $positionId, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM election_ballots_cast WHERE position_id = ? AND student_id = ?');
    $stmt->execute([$positionId, $studentId]);
    return (bool) $stmt->fetchColumn();
}

/** This student's own candidacy row for a position, if any. */
function election_student_application(PDO $pdo, int $positionId, int $studentId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM election_candidates WHERE position_id = ? AND student_id = ?');
    $stmt->execute([$positionId, $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** "Currently enrolled" -- same idiom settings.php's Close Year logic already uses. */
function election_eligible_voter_count(PDO $pdo, int $schoolId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE school_id = ? AND graduated_year IS NULL');
    $stmt->execute([$schoolId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Per-candidate vote count + percentage of this position's total votes.
 * Reused verbatim by the admin results page (always) and the student
 * results page (only once election_phase() === 'closed').
 */
function election_tally_for_position(PDO $pdo, int $positionId): array
{
    $stmt = $pdo->prepare("
        SELECT c.id, c.candidate_name, c.photo_path, COUNT(v.id) AS votes
        FROM election_candidates c
        LEFT JOIN election_votes v ON v.candidate_id = c.id
        WHERE c.position_id = ? AND c.status = 'Approved'
        GROUP BY c.id
        ORDER BY c.display_order, c.candidate_name
    ");
    $stmt->execute([$positionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = array_sum(array_column($rows, 'votes'));
    foreach ($rows as &$row) {
        $row['votes'] = (int) $row['votes'];
        $row['percentage'] = $total > 0 ? round(($row['votes'] / $total) * 100, 1) : 0.0;
    }
    unset($row);

    return ['candidates' => $rows, 'total_votes' => $total];
}

/** Voted vs. eligible counts + percentage -- the live turnout stat. */
function election_turnout_for_position(PDO $pdo, int $positionId, int $schoolId): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM election_ballots_cast WHERE position_id = ?');
    $stmt->execute([$positionId]);
    $voted = (int) $stmt->fetchColumn();

    $eligible = election_eligible_voter_count($pdo, $schoolId);

    return [
        'voted' => $voted,
        'eligible' => $eligible,
        'percentage' => $eligible > 0 ? round(($voted / $eligible) * 100, 1) : 0.0,
    ];
}

/**
 * Every position from every election currently in the 'voting' phase, its
 * Approved candidates only, plus a per-position already-voted flag. Drives
 * ballot.php.
 */
function election_approved_ballot_for_student(PDO $pdo, int $schoolId, int $studentId): array
{
    $elec_stmt = $pdo->prepare("SELECT * FROM elections WHERE school_id = ? AND status = 'Published' ORDER BY opens_at");
    $elec_stmt->execute([$schoolId]);

    $ballot = [];
    foreach ($elec_stmt->fetchAll(PDO::FETCH_ASSOC) as $election) {
        if (election_phase($election, $pdo) !== 'voting') {
            continue;
        }

        $pos_stmt = $pdo->prepare('SELECT * FROM election_positions WHERE election_id = ? ORDER BY display_order, title');
        $pos_stmt->execute([$election['id']]);
        $positions = $pos_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($positions as &$position) {
            $cand_stmt = $pdo->prepare("SELECT id, candidate_name, manifesto, photo_path FROM election_candidates WHERE position_id = ? AND status = 'Approved' ORDER BY display_order, candidate_name");
            $cand_stmt->execute([$position['id']]);
            $position['candidates'] = $cand_stmt->fetchAll(PDO::FETCH_ASSOC);
            $position['already_voted'] = election_student_has_voted($pdo, (int) $position['id'], $studentId);
            $position['election_title'] = $election['title'];
        }
        unset($position);

        if ($positions) {
            $ballot = array_merge($ballot, $positions);
        }
    }

    return $ballot;
}

/**
 * Transactional vote cast. Re-validates server-side that the candidate is
 * actually Approved and belongs to the position, and that the election is
 * genuinely in the 'voting' phase right now -- never trusts the POSTed IDs
 * or client-side timing alone. election_ballots_cast is inserted FIRST --
 * it's the row guarded by the UNIQUE key, so a repeat vote fails there,
 * before the vote row is ever reached.
 */
function election_cast_vote(PDO $pdo, int $schoolId, int $positionId, int $candidateId, int $studentId): array
{
    // Position must belong to an election in the caller's OWN school --
    // without this, a student could pass any position_id/candidate_id
    // pair (small sequential integers) and cast a vote into a different
    // school's election entirely. Every other elections page already
    // scopes its lookups by school_id; this one and
    // election_submit_application() below were the two write paths that
    // didn't.
    $pos_stmt = $pdo->prepare('
        SELECT p.*, e.status AS election_status, e.opens_at, e.closes_at
        FROM election_positions p
        JOIN elections e ON e.id = p.election_id
        WHERE p.id = ? AND e.school_id = ?
    ');
    $pos_stmt->execute([$positionId, $schoolId]);
    $position = $pos_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$position || election_phase([
        'status' => $position['election_status'],
        'opens_at' => $position['opens_at'],
        'closes_at' => $position['closes_at'],
    ], $pdo) !== 'voting') {
        return ['ok' => false, 'reason' => 'not_open'];
    }

    $cand_stmt = $pdo->prepare("SELECT id FROM election_candidates WHERE id = ? AND position_id = ? AND status = 'Approved'");
    $cand_stmt->execute([$candidateId, $positionId]);
    if (!$cand_stmt->fetchColumn()) {
        return ['ok' => false, 'reason' => 'invalid_candidate'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO election_ballots_cast (position_id, student_id) VALUES (?, ?)')
            ->execute([$positionId, $studentId]);
        $pdo->prepare('INSERT INTO election_votes (position_id, candidate_id) VALUES (?, ?)')
            ->execute([$positionId, $candidateId]);
        $pdo->commit();
        return ['ok' => true, 'reason' => null];
    } catch (\PDOException $e) {
        $pdo->rollBack();
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'reason' => 'already_voted'];
        }
        throw $e;
    }
}

/**
 * A student's self-nomination. Validates the position's election is in the
 * 'nominating' phase, catches the UNIQUE(position_id, student_id) duplicate
 * with a friendly result instead of a crash.
 */
function election_submit_application(PDO $pdo, int $schoolId, int $positionId, int $studentId, string $studentName, string $manifesto, ?array $photoFile): array
{
    // Same school_id scoping as election_cast_vote() above -- a student
    // could otherwise self-nominate for a position in another school's
    // election just by supplying its position_id.
    $pos_stmt = $pdo->prepare('
        SELECT p.*, e.status AS election_status, e.opens_at, e.closes_at
        FROM election_positions p
        JOIN elections e ON e.id = p.election_id
        WHERE p.id = ? AND e.school_id = ?
    ');
    $pos_stmt->execute([$positionId, $schoolId]);
    $position = $pos_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$position || election_phase([
        'status' => $position['election_status'],
        'opens_at' => $position['opens_at'],
        'closes_at' => $position['closes_at'],
    ], $pdo) !== 'nominating') {
        return ['ok' => false, 'reason' => 'not_open'];
    }

    $photo_path = null;
    if ($photoFile !== null && !empty($photoFile['name'])) {
        $saved = election_save_candidate_photo($photoFile, $positionId);
        if ($saved !== null) {
            $photo_path = $saved['path'];
        }
    }

    try {
        $pdo->prepare('
            INSERT INTO election_candidates (position_id, student_id, candidate_name, manifesto, photo_path, status)
            VALUES (?, ?, ?, ?, ?, "Pending")
        ')->execute([$positionId, $studentId, $studentName, $manifesto !== '' ? $manifesto : null, $photo_path]);
        return ['ok' => true, 'reason' => null];
    } catch (\PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'reason' => 'already_applied'];
        }
        throw $e;
    }
}

/**
 * Same shape as ilearning_save_attachment() (self-healing mkdir, prefixed
 * collision-safe filename, extension allow-list) -- but called from the
 * student's own application submission, not an admin form. Photos are
 * public/low-sensitivity by design (meant to be shown to every voter once
 * approved), so this uses the same plain public uploads/ convention as
 * every other image upload in this app, not the gated ilearning_private
 * pattern.
 */
function election_save_candidate_photo(array $file, int $positionId): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return null;
    }

    $dir = __DIR__ . '/../uploads/election_candidates';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'cand_' . $positionId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    $destPath = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    return ['path' => 'uploads/election_candidates/' . $filename];
}

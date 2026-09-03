<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR ELECTIONS — ADMIN PAGES HELPERS (Positions / Candidates / Results)
|--------------------------------------------------------------------------
| Shared by the classic elections/{positions,candidates,results}.php pages
| and their scholar/api/admin/elections_* JSON twins. Logic ported verbatim
| from the original pages. Requires elections/_election_helpers.php to
| already be loaded by the caller.
*/

/** Resolves + authorizes one election for this school. Returns null if not found (caller should 404/redirect). */
function admin_election_resolve(PDO $pdo, int $school_id, int $election_id): ?array
{
    $elec_stmt = $pdo->prepare('SELECT * FROM elections WHERE id = ? AND school_id = ?');
    $elec_stmt->execute([$election_id, $school_id]);
    $election = $elec_stmt->fetch(PDO::FETCH_ASSOC);
    return $election ?: null;
}

function admin_election_locked(array $election, PDO $pdo): bool
{
    $phase = election_phase($election, $pdo);
    return $phase === 'voting' || $phase === 'closed';
}

// ---- Positions ----

function admin_election_positions_list(PDO $pdo, int $election_id): array
{
    $pos_stmt = $pdo->prepare('SELECT * FROM election_positions WHERE election_id = ? ORDER BY display_order, title');
    $pos_stmt->execute([$election_id]);
    return $pos_stmt->fetchAll(PDO::FETCH_ASSOC);
}

function admin_election_position_add(PDO $pdo, int $election_id, string $title): array
{
    if ($title === '') {
        return ['ok' => false, 'message' => 'Position title is required.'];
    }
    $order_stmt = $pdo->prepare('SELECT COALESCE(MAX(display_order),0)+1 FROM election_positions WHERE election_id = ?');
    $order_stmt->execute([$election_id]);
    $pdo->prepare('INSERT INTO election_positions (election_id, title, display_order) VALUES (?, ?, ?)')
        ->execute([$election_id, $title, (int) $order_stmt->fetchColumn()]);
    return ['ok' => true, 'message' => 'Position added.'];
}

function admin_election_position_delete(PDO $pdo, int $election_id, int $position_id): array
{
    $pdo->prepare('DELETE FROM election_positions WHERE id = ? AND election_id = ?')
        ->execute([$position_id, $election_id]);
    return ['ok' => true, 'message' => 'Position removed.'];
}

// ---- Candidates ----

/** Positions with their candidates attached, same shape the classic candidates.php page loops over. */
function admin_election_candidates_by_position(PDO $pdo, int $election_id): array
{
    $positions = admin_election_positions_list($pdo, $election_id);
    $cand_stmt = $pdo->prepare('SELECT * FROM election_candidates WHERE position_id = ? ORDER BY status, candidate_name');
    foreach ($positions as &$p) {
        $cand_stmt->execute([$p['id']]);
        $p['candidates'] = $cand_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($p);
    return $positions;
}

function admin_election_candidate_review(PDO $pdo, int $school_id, int $election_id, ?int $staff_id, int $candidate_id, string $decision): array
{
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        return ['ok' => false, 'message' => 'Invalid decision.'];
    }
    // Scope the UPDATE through position -> election -> school_id so a
    // candidate_id from a different school can never be touched here.
    $upd = $pdo->prepare('
        UPDATE election_candidates c
        JOIN election_positions p ON p.id = c.position_id
        JOIN elections e ON e.id = p.election_id
        SET c.status = ?, c.reviewed_by = ?, c.reviewed_at = NOW()
        WHERE c.id = ? AND e.id = ? AND e.school_id = ?
    ');
    $upd->execute([$decision, $staff_id && $staff_id > 0 ? $staff_id : null, $candidate_id, $election_id, $school_id]);
    return ['ok' => true, 'message' => 'Candidacy ' . strtolower($decision) . '.'];
}

// ---- Results ----

/** Positions with turnout + tally attached, same shape the classic results.php page loops over. */
function admin_election_results(PDO $pdo, int $school_id, int $election_id): array
{
    $positions = admin_election_positions_list($pdo, $election_id);
    foreach ($positions as &$p) {
        $p['turnout'] = election_turnout_for_position($pdo, (int) $p['id'], $school_id);
        $p['tally'] = election_tally_for_position($pdo, (int) $p['id']);
    }
    unset($p);
    return $positions;
}

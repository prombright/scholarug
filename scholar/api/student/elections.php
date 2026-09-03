<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — STUDENT: ELECTIONS BALLOT (JSON)
|--------------------------------------------------------------------------
| JSON twin of elections/ballot.php, built on the same
| elections/_election_helpers.php -- election_cast_vote() already does all
| the real validation (position belongs to this school, election is
| actually in the 'voting' phase, candidate is Approved), so this file
| only shapes the request/response and keeps the shuffled-order anonymity
| mitigation the classic page already used.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../elections/_election_helpers.php';
require_role(['student']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$student_id = current_student_id();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $votes = is_array($body['votes'] ?? null) ? $body['votes'] : []; // position_id => candidate_id

    $position_ids = array_keys($votes);
    shuffle($position_ids); // anonymity mitigation -- see _election_helpers.php's header comment

    $notices = [];
    foreach ($position_ids as $position_id) {
        $notices[$position_id] = election_cast_vote($pdo, $school_id, (int) $position_id, (int) $votes[$position_id], $student_id);
    }

    echo json_encode(['success' => true, 'notices' => $notices, 'ballot' => election_approved_ballot_for_student($pdo, $school_id, $student_id)], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'success' => true,
    'ballot' => election_approved_ballot_for_student($pdo, $school_id, $student_id),
    'scholar_base' => rtrim(SCHOLAR_BASE, '/') . '/',
], JSON_UNESCAPED_SLASHES);

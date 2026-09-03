<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: ELECTION CANDIDATES REVIEW (JSON)
|--------------------------------------------------------------------------
| JSON twin of elections/candidates.php, built on
| elections/_admin_pages_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../elections/_election_helpers.php';
require_once __DIR__ . '/../../elections/_admin_pages_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

function admin_elections_candidates_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$election_id = (int) ($_GET['election_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];
$message = null;

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $election_id = (int) ($body['election_id'] ?? $election_id);
    $election = admin_election_resolve($pdo, $school_id, $election_id);
    if (!$election) {
        admin_elections_candidates_json_error('Election not found.', 404);
    }
    $locked = admin_election_locked($election, $pdo);

    if ($locked) {
        admin_elections_candidates_json_error('Candidates can no longer be approved or rejected once voting has started.');
    }

    $result = admin_election_candidate_review(
        $pdo, $school_id, $election_id, $staff_id,
        (int) ($body['candidate_id'] ?? 0),
        (string) ($body['decision'] ?? '')
    );
    if (!$result['ok']) {
        admin_elections_candidates_json_error($result['message']);
    }
    $message = $result['message'];
}

$election = admin_election_resolve($pdo, $school_id, $election_id);
if (!$election) {
    admin_elections_candidates_json_error('Election not found.', 404);
}

echo json_encode([
    'success' => true,
    'election' => $election,
    'locked' => admin_election_locked($election, $pdo),
    'positions' => admin_election_candidates_by_position($pdo, $election_id),
    'message' => $message,
], JSON_UNESCAPED_SLASHES);

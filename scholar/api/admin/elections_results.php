<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: ELECTION RESULTS / LIVE TURNOUT (JSON)
|--------------------------------------------------------------------------
| JSON twin of elections/results.php, built on
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
$election_id = (int) ($_GET['election_id'] ?? 0);

$election = admin_election_resolve($pdo, $school_id, $election_id);
if (!$election) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Election not found.']);
    exit;
}

echo json_encode([
    'success' => true,
    'election' => $election,
    'phase' => election_phase($election, $pdo),
    'positions' => admin_election_results($pdo, $school_id, $election_id),
], JSON_UNESCAPED_SLASHES);

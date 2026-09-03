<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL ADMIN: ELECTION POSITIONS (JSON)
|--------------------------------------------------------------------------
| JSON twin of elections/positions.php, built on
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

function admin_elections_positions_json_error(string $message, int $code = 400): void
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
        admin_elections_positions_json_error('Election not found.', 404);
    }
    $locked = admin_election_locked($election, $pdo);

    $action = $body['action'] ?? '';
    if ($locked) {
        admin_elections_positions_json_error('Positions can no longer be changed once voting has started.');
    } elseif ($action === 'add_position') {
        $message = admin_election_position_add($pdo, $election_id, trim((string) ($body['title'] ?? '')));
        if (!$message['ok']) {
            admin_elections_positions_json_error($message['message']);
        }
    } elseif ($action === 'delete_position') {
        $message = admin_election_position_delete($pdo, $election_id, (int) ($body['position_id'] ?? 0));
    } else {
        admin_elections_positions_json_error('Unknown action.');
    }
}

$election = admin_election_resolve($pdo, $school_id, $election_id);
if (!$election) {
    admin_elections_positions_json_error('Election not found.', 404);
}

echo json_encode([
    'success' => true,
    'election' => $election,
    'locked' => admin_election_locked($election, $pdo),
    'positions' => admin_election_positions_list($pdo, $election_id),
    'message' => $message['message'] ?? null,
], JSON_UNESCAPED_SLASHES);

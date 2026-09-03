<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — STUDENT ELECTIONS: LIST/CREATE (JSON)
|--------------------------------------------------------------------------
| JSON twin of elections/index.php, built on elections/_index_helpers.php.
| Positions/Candidates/Results stay classic links -- see this file's Vue
| page for why.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_role(['school_admin']);
require_once __DIR__ . '/../../elections/_election_helpers.php';
require_once __DIR__ . '/../../elections/_index_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$staff_id = current_staff_id();

function admin_elections_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'elections' => admin_elections_fetch_list($pdo, $school_id),
        'current_term' => current_term(),
        'current_year' => current_year(),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'create_election') {
        $result = admin_elections_create(
            $pdo, $school_id, $staff_id,
            trim((string) ($body['title'] ?? '')), trim((string) ($body['term'] ?? current_term())), trim((string) ($body['year'] ?? current_year())),
            trim((string) ($body['opens_at'] ?? '')), trim((string) ($body['closes_at'] ?? ''))
        );
        if (!$result['ok']) {
            admin_elections_json_error($result['message']);
        }
    } elseif ($action === 'publish_election') {
        admin_elections_publish($pdo, $school_id, (int) ($body['election_id'] ?? 0));
        $result = ['ok' => true, 'message' => 'Election published. Students can now apply for its positions.'];
    } else {
        admin_elections_json_error('Unknown action.');
    }

    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'elections' => admin_elections_fetch_list($pdo, $school_id),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

admin_elections_json_error('Method not allowed.', 405);

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — SCHOOL SETTINGS (JSON)
|--------------------------------------------------------------------------
| JSON twin of settings.php, built on _settings_helpers.php. Close Term
| and Close Year are destructive, irreversible bulk operations -- this
| file adds no new confirmation protocol beyond what the classic page's
| confirm() dialogs already gate; the Vue page carries the same dialogs.
| File uploads (school logo) go through the classic page's multipart POST
| directly, same reasoning as every other file-upload path in this app --
| the Vue settings page links back to it for that one field.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../_settings_helpers.php';
require_role(['school_admin']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

function admin_settings_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $school = admin_settings_fetch_school($pdo, $school_id);
    } catch (Exception $e) {
        admin_settings_json_error('CRITICAL STRUCTURAL ARCHITECTURE RECOVERY FAULT: ' . $e->getMessage(), 500);
    }
    echo json_encode(['success' => true, 'school' => $school], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'save_profile') {
        // No file upload here -- logo changes go through the classic page's
        // multipart form (see this file's header comment). Every other
        // field goes through the same admin_settings_save() the classic
        // page uses, just with an empty $_FILES so the logo is left as-is.
        $post = $body;
        $post['existing_logo_path'] = $body['existing_logo_path'] ?? null;
        $result = admin_settings_save($pdo, $school_id, $post, []);
        if (!$result['ok']) {
            admin_settings_json_error($result['message']);
        }
        if (isset($_SESSION)) {
            $_SESSION['school_name'] = trim($body['school_name'] ?? '');
            $_SESSION['school_badge'] = $result['school_badge'];
            $_SESSION['school_location'] = trim($body['address'] ?? '');
            $_SESSION['current_term'] = trim($body['current_term'] ?? 'Term 1');
            $_SESSION['current_year'] = trim($body['current_academic_year'] ?? '2026');
        }
        echo json_encode(['success' => true, 'message' => $result['message'], 'school' => admin_settings_fetch_school($pdo, $school_id)], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'close_term') {
        $school = admin_settings_fetch_school($pdo, $school_id);
        $term_to_close = $school['current_term'] ?? 'Term 1';
        $year_to_close = $school['current_year'] ?? (string) date('Y');

        $result = admin_settings_close_term($pdo, $school_id, $term_to_close, $year_to_close);
        if (!$result['ok']) {
            admin_settings_json_error($result['message']);
        }
        $_SESSION['current_term'] = $result['new_term'];
        echo json_encode(['success' => true, 'message' => $result['message'], 'school' => admin_settings_fetch_school($pdo, $school_id)], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'close_year') {
        $school = admin_settings_fetch_school($pdo, $school_id);
        $year_to_close = $school['current_year'] ?? (string) date('Y');
        $school_type = $school['school_type'] ?? 'Secondary';

        $result = admin_settings_close_year($pdo, $school_id, $year_to_close, $school_type);
        if (!$result['ok']) {
            admin_settings_json_error($result['message']);
        }
        $_SESSION['current_year'] = $result['new_year'];
        $_SESSION['current_term'] = 'Term 1';
        echo json_encode(['success' => true, 'message' => $result['message'], 'school' => admin_settings_fetch_school($pdo, $school_id)], JSON_UNESCAPED_SLASHES);
        exit;
    }

    admin_settings_json_error('Unknown action.');
}

admin_settings_json_error('Method not allowed.', 405);

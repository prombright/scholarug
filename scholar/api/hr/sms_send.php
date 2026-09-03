<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — HR BULK SMS: SEND (JSON)
|--------------------------------------------------------------------------
| JSON twin of hr/sms/send.php, built on hr/sms/_send_helpers.php.
| Two-step preview/confirm preserved exactly.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../lib/ScholarSmsWallet.php';
require_once __DIR__ . '/../../lib/ScholarSmsPricing.php';
require_once __DIR__ . '/../../hr/sms/_send_helpers.php';
require_role(['school_admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$currency = ScholarSmsPricing::currency($pdo);
$balance = ScholarSmsWallet::balance($pdo, $school_id);

function hr_sms_send_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$groups = hr_sms_send_groups($pdo, $school_id);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'currency' => $currency,
        'balance' => $balance,
        'groups' => $groups,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $selection = (string) ($body['group_selection'] ?? '');
    $message = trim((string) ($body['message'] ?? ''));

    if ($action === 'preview') {
        $result = hr_sms_send_preview($pdo, $school_id, $balance, $selection, $message, $groups);
        if (!$result['ok']) {
            hr_sms_send_json_error($result['message']);
        }
        echo json_encode(['success' => true, 'preview' => $result['preview'], 'currency' => $currency], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'confirm_send') {
        $result = hr_sms_send_confirm($pdo, $school_id, $user_id, $selection, $message, $groups);
        if (!$result['ok']) {
            hr_sms_send_json_error($result['message']);
        }
        echo json_encode([
            'success' => true,
            'result' => $result['result'],
            'balance' => ScholarSmsWallet::balance($pdo, $school_id),
            'currency' => $currency,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    hr_sms_send_json_error('Unknown action.');
}

hr_sms_send_json_error('Method not allowed.', 405);

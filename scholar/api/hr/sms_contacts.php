<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — HR BULK SMS: CONTACTS (JSON)
|--------------------------------------------------------------------------
| JSON twin of hr/sms/contacts.php, built on hr/sms/_contacts_helpers.php.
| The CSV/.xlsx import stays a classic multipart POST straight to
| hr/sms/contacts.php -- see importUrl() in the Vue app's api.js.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../hr/sms/_contacts_helpers.php';
require_role(['school_admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$message = null;

function hr_sms_contacts_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';

    if ($action === 'add_class_group') {
        $result = hr_sms_contacts_add_class_group($pdo, $school_id, (int) ($body['class_id'] ?? 0));
    } elseif ($action === 'delete_group') {
        $result = hr_sms_contacts_delete_group($pdo, $school_id, (int) ($body['group_id'] ?? 0));
    } else {
        hr_sms_contacts_json_error('Unknown action.');
    }

    if (!$result['ok']) {
        hr_sms_contacts_json_error($result['message']);
    }
    $message = $result['message'];
}

echo json_encode([
    'success' => true,
    'message' => $message,
] + hr_sms_contacts_state($pdo, $school_id), JSON_UNESCAPED_SLASHES);

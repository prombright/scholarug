<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — HR BULK SMS: WHATSAPP SETTINGS (JSON)
|--------------------------------------------------------------------------
| JSON twin of hr/sms/whatsapp_settings.php, built on
| hr/sms/_whatsapp_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../hr/sms/_whatsapp_helpers.php';
require_role(['school_admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? 'save';

    if ($action === 'disable') {
        $result = hr_sms_whatsapp_disable($pdo, $school_id);
    } else {
        $existing = hr_sms_whatsapp_settings($pdo, $school_id);
        $result = hr_sms_whatsapp_save(
            $pdo, $school_id, $existing,
            trim((string) ($body['sender_name'] ?? '')), trim((string) ($body['whatsapp_number'] ?? '')),
            trim((string) ($body['phone_number_id'] ?? '')), trim((string) ($body['access_token'] ?? ''))
        );
    }

    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }
    $message = $result['message'];
}

echo json_encode([
    'success' => true,
    'settings' => hr_sms_whatsapp_settings($pdo, $school_id),
    'message' => $message,
], JSON_UNESCAPED_SLASHES);

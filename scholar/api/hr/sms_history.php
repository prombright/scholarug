<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — HR BULK SMS: HISTORY (JSON)
|--------------------------------------------------------------------------
| JSON twin of hr/sms/history.php, built on hr/sms/_history_helpers.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../hr/sms/_history_helpers.php';
require_role(['school_admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();
$campaigns = hr_sms_history_campaigns($pdo, $school_id);

$open_campaign_id = isset($_GET['campaign_id']) && $_GET['campaign_id'] !== '' ? (int) $_GET['campaign_id'] : null;
$open_campaign = null;
$recipients = [];

if ($open_campaign_id !== null) {
    foreach ($campaigns as $c) {
        if ((int) $c['id'] === $open_campaign_id) {
            $open_campaign = $c;
        }
    }
    if ($open_campaign !== null) {
        $recipients = hr_sms_history_recipients($pdo, $open_campaign_id);
    }
}

echo json_encode([
    'success' => true,
    'campaigns' => $campaigns,
    'open_campaign' => $open_campaign,
    'recipients' => $recipients,
], JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR API — HR BULK SMS: WALLET (JSON)
|--------------------------------------------------------------------------
| JSON twin of hr/sms/wallet.php, built on hr/sms/_wallet_helpers.php. The
| actual top-up flow (hr/sms/topup_initiate.php + topup_status.php) is
| real-money payment code the Vue page calls directly, unchanged.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../hr/sms/_wallet_helpers.php';
require_role(['school_admin', 'hr']);

header('Content-Type: application/json; charset=utf-8');

$school_id = current_school_id();

echo json_encode([
    'success' => true,
] + hr_sms_wallet_state($pdo, $school_id), JSON_UNESCAPED_SLASHES);

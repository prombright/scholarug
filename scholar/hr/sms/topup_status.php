<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SMS WALLET TOP-UP: STATUS (AJAX, polled from wallet.php)
|--------------------------------------------------------------------------
| The ONLY place a top-up ever credits the SMS wallet. Guarded by a
| single UPDATE ... WHERE status = 'pending' before crediting, so a
| request already resolved by an earlier poll (or a second tab) can
| never be credited twice.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../lib/ScholarSmsWallet.php';
require_once __DIR__ . '/../../../payments/Gateways.php';

require_role(['school_admin', 'hr']);

header('Content-Type: application/json');

$school_id = current_school_id();
$reference = (string) ($_GET['reference'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM sms_topup_requests WHERE reference = ? AND school_id = ? LIMIT 1");
$stmt->execute([$reference, $school_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => 'Top-up request not found.']);
    exit;
}

if ($request['status'] !== 'pending') {
    echo json_encode([
        'status' => $request['status'],
        'reference' => $reference,
        'error' => $request['failure_reason'],
    ]);
    exit;
}

$gateway = Gateways::mobileMoney($request['network']);
if (!$gateway || empty($request['provider_reference'])) {
    echo json_encode(['status' => 'pending', 'reference' => $reference]);
    exit;
}

$check = $gateway->checkStatus($request['provider_reference']);

if ($check['status'] === 'successful') {
    $claim = $pdo->prepare("UPDATE sms_topup_requests SET status = 'successful' WHERE id = ? AND status = 'pending'");
    $claim->execute([$request['id']]);

    if ($claim->rowCount() === 1) {
        ScholarSmsWallet::credit(
            $pdo,
            $school_id,
            (float) $request['amount'],
            'deposit',
            $request['reference'],
            strtoupper($request['network']) . ' Mobile Money'
        );
    }

    echo json_encode(['status' => 'successful', 'reference' => $reference]);
} elseif ($check['status'] === 'failed') {
    $reason = $check['error'] ?? 'Payment failed or was declined.';
    $pdo->prepare("UPDATE sms_topup_requests SET status = 'failed', failure_reason = ? WHERE id = ? AND status = 'pending'")
        ->execute([$reason, $request['id']]);

    echo json_encode(['status' => 'failed', 'reference' => $reference, 'error' => $reason]);
} else {
    echo json_encode(['status' => 'pending', 'reference' => $reference]);
}
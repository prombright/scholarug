<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SMS WALLET TOP-UP: INITIATE (AJAX)
|--------------------------------------------------------------------------
| Same shape as bulksms/topup_initiate.php / scholar/subscription_initiate.php:
| creates an sms_topup_requests row up front, then asks the chosen gateway
| to push a payment prompt. Never credits the wallet here -- only
| topup_status.php does that, once a payment is CONFIRMED.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_guard.php';
require_once __DIR__ . '/../../lib/ScholarSmsPricing.php';
require_once __DIR__ . '/../../../payments/Gateways.php';
require_once __DIR__ . '/../../../payments/CountryCodes.php';

require_role(['school_admin', 'hr']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed.']);
    exit;
}

$school_id = current_school_id();
$network = $_POST['network'] ?? '';
$dialCode = trim($_POST['dial_code'] ?? '');
$localNumber = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? ''));
$amount = (float) ($_POST['amount'] ?? 0);

if (!in_array($network, ['mtn', 'airtel'], true)) {
    echo json_encode(['status' => 'error', 'error' => 'Choose MTN or Airtel.']);
    exit;
}

$validDialCodes = array_column(CountryCodes::LIST, 'dial');
if (!in_array($dialCode, $validDialCodes, true)) {
    echo json_encode(['status' => 'error', 'error' => 'Choose a valid country code.']);
    exit;
}

if ($localNumber === '' || strlen($localNumber) < 6 || strlen($localNumber) > 12) {
    echo json_encode(['status' => 'error', 'error' => 'Enter a valid phone number.']);
    exit;
}

if ($amount < 500 || $amount > 5000000) {
    echo json_encode(['status' => 'error', 'error' => 'Enter an amount between 500 and 5,000,000.']);
    exit;
}

$phoneE164 = $dialCode . ltrim($localNumber, '0');
$currency = ScholarSmsPricing::currency($pdo);
$reference = 'SMSTOPUP-' . strtoupper(bin2hex(random_bytes(8)));

$insert = $pdo->prepare(
    "INSERT INTO sms_topup_requests (school_id, network, phone, amount, currency, reference, status)
     VALUES (?, ?, ?, ?, ?, ?, 'pending')"
);
$insert->execute([$school_id, $network, $phoneE164, $amount, $currency, $reference]);
$requestId = (int) $pdo->lastInsertId();

$gateway = Gateways::mobileMoney($network);

if (!$gateway || !$gateway->isConfigured()) {
    $label = $gateway ? $gateway->label() : 'This provider';
    $pdo->prepare("UPDATE sms_topup_requests SET status = 'failed', failure_reason = ? WHERE id = ?")
        ->execute([$label . ' is not connected yet.', $requestId]);

    echo json_encode([
        'status' => 'not-configured',
        'reference' => $reference,
        'message' => $label . ' isn\'t connected yet — this is demo mode, no real charge will occur.',
    ]);
    exit;
}

$result = $gateway->initiate($phoneE164, $amount, $currency, $reference);

if ($result['status'] === 'pending') {
    $pdo->prepare("UPDATE sms_topup_requests SET provider_reference = ? WHERE id = ?")
        ->execute([$result['provider_reference'] ?? null, $requestId]);

    echo json_encode([
        'status' => 'pending',
        'reference' => $reference,
        'message' => 'Check your phone to approve the payment.',
    ]);
} else {
    $error = $result['error'] ?? 'Could not start the payment.';
    $pdo->prepare("UPDATE sms_topup_requests SET status = 'failed', failure_reason = ? WHERE id = ?")
        ->execute([$error, $requestId]);

    echo json_encode(['status' => 'error', 'reference' => $reference, 'error' => $error]);
}
<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SUBSCRIPTION PAYMENT: INITIATE (AJAX)
|--------------------------------------------------------------------------
| Same shape as bulksms/subscription_initiate.php: creates a
| subscription_charges row up front, then asks the chosen gateway to push
| a payment prompt. Never extends the subscription here -- that only ever
| happens in subscription_status.php, once a payment is CONFIRMED.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/auth_guard.php';
require_role(['headteacher', 'school_admin', 'bursar']);
require_once __DIR__ . '/../payments/Gateways.php';
require_once __DIR__ . '/../payments/CountryCodes.php';
require_once __DIR__ . '/../payments/Plans.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed.']);
    exit;
}

$schoolId = current_school_id();
$network = $_POST['network'] ?? '';
$dialCode = trim($_POST['dial_code'] ?? '');
$localNumber = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? ''));
$planCode = $_POST['plan_code'] ?? '';

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

$plan = Plans::get($planCode);
if ($plan === null || $plan['app'] !== 'scholar') {
    echo json_encode(['status' => 'error', 'error' => 'Choose a valid plan.']);
    exit;
}

$subStmt = $pdo->prepare('SELECT id FROM subscriptions WHERE school_id = ?');
$subStmt->execute([$schoolId]);
$subscriptionId = $subStmt->fetchColumn();
if ($subscriptionId === false) {
    echo json_encode(['status' => 'error', 'error' => 'No subscription found for this school.']);
    exit;
}

$phoneE164 = $dialCode . ltrim($localNumber, '0');
$reference = 'SUB-' . strtoupper(bin2hex(random_bytes(8)));

$insert = $pdo->prepare(
    "INSERT INTO subscription_charges (subscription_id, plan_code, network, phone, amount, currency, reference, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
);
$insert->execute([$subscriptionId, $planCode, $network, $phoneE164, $plan['amount'], $plan['currency'], $reference]);
$chargeId = (int) $pdo->lastInsertId();

$gateway = Gateways::mobileMoney($network);

if (!$gateway || !$gateway->isConfigured()) {
    $label = $gateway ? $gateway->label() : 'This provider';
    $pdo->prepare("UPDATE subscription_charges SET status = 'failed', failure_reason = ? WHERE id = ?")
        ->execute([$label . ' is not connected yet.', $chargeId]);

    echo json_encode([
        'status' => 'not-configured',
        'reference' => $reference,
        'message' => $label . ' isn\'t connected yet — please contact ScholarUg support.',
    ]);
    exit;
}

$result = $gateway->initiate($phoneE164, (float) $plan['amount'], $plan['currency'], $reference);

if ($result['status'] === 'pending') {
    $pdo->prepare("UPDATE subscription_charges SET provider_reference = ? WHERE id = ?")
        ->execute([$result['provider_reference'] ?? null, $chargeId]);

    echo json_encode([
        'status' => 'pending',
        'reference' => $reference,
        'message' => $gateway instanceof StubMomoGateway
            ? 'Demo mode: confirming automatically...'
            : 'Check your phone to approve the payment.',
    ]);
} else {
    $error = $result['error'] ?? 'Could not start the payment.';
    $pdo->prepare("UPDATE subscription_charges SET status = 'failed', failure_reason = ? WHERE id = ?")
        ->execute([$error, $chargeId]);

    echo json_encode(['status' => 'error', 'reference' => $reference, 'error' => $error]);
}

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — iLEARNING LIVE-CLASS ADD-ON: INITIATE (AJAX)
|--------------------------------------------------------------------------
| Mirrors scholar/subscription_initiate.php exactly, against
| ilearning_addons/ilearning_addon_charges instead of subscriptions/
| subscription_charges -- this is a separate purchase from the base
| Scholar plan (a school has exactly one subscriptions row, this add-on
| gets its own table).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../auth_guard.php';
require_role(['headteacher', 'school_admin', 'bursar']);
require_once __DIR__ . '/../../payments/Gateways.php';
require_once __DIR__ . '/../../payments/CountryCodes.php';
require_once __DIR__ . '/../../payments/Plans.php';

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
if ($plan === null || $plan['app'] !== 'scholar_ilearning') {
    echo json_encode(['status' => 'error', 'error' => 'Choose a valid plan.']);
    exit;
}

// First-time purchase: no ilearning_addons row exists yet for this school
// (unlike the base subscription, which is created at onboarding, this one
// is only created the first time a school actually attempts to buy it).
$addonStmt = $pdo->prepare('SELECT id FROM ilearning_addons WHERE school_id = ?');
$addonStmt->execute([$schoolId]);
$addonId = $addonStmt->fetchColumn();

if ($addonId === false) {
    $pdo->prepare(
        "INSERT INTO ilearning_addons (school_id, plan_code, billing_cycle, status, trial_ends_at, amount, currency)
         VALUES (?, ?, 'trial', 'trialing', NOW(), 0, ?)"
    )->execute([$schoolId, $planCode, $plan['currency']]);
    $addonId = (int) $pdo->lastInsertId();
}

$phoneE164 = $dialCode . ltrim($localNumber, '0');
$reference = 'ADDON-' . strtoupper(bin2hex(random_bytes(8)));

$insert = $pdo->prepare(
    "INSERT INTO ilearning_addon_charges (addon_id, plan_code, network, phone, amount, currency, reference, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
);
$insert->execute([$addonId, $planCode, $network, $phoneE164, $plan['amount'], $plan['currency'], $reference]);
$chargeId = (int) $pdo->lastInsertId();

$gateway = Gateways::mobileMoney($network);

if (!$gateway || !$gateway->isConfigured()) {
    $label = $gateway ? $gateway->label() : 'This provider';
    $pdo->prepare("UPDATE ilearning_addon_charges SET status = 'failed', failure_reason = ? WHERE id = ?")
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
    $pdo->prepare("UPDATE ilearning_addon_charges SET provider_reference = ? WHERE id = ?")
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
    $pdo->prepare("UPDATE ilearning_addon_charges SET status = 'failed', failure_reason = ? WHERE id = ?")
        ->execute([$error, $chargeId]);

    echo json_encode(['status' => 'error', 'reference' => $reference, 'error' => $error]);
}

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — iLEARNING ADD-ON: STATUS (AJAX, polled from addon_upgrade.php)
|--------------------------------------------------------------------------
| Mirrors scholar/subscription_status.php exactly, against
| ilearning_addons/ilearning_addon_charges. Same idempotency guarantee via
| SubscriptionCharge::confirmIfPending() -- 'ilearning_addon_charges' is
| in its ALLOWED_TABLES whitelist.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../auth_guard.php';
require_role(['headteacher', 'school_admin', 'bursar']);
require_once __DIR__ . '/../../payments/Gateways.php';
require_once __DIR__ . '/../../payments/SubscriptionCharge.php';
require_once __DIR__ . '/../../payments/Plans.php';

header('Content-Type: application/json');

$schoolId = current_school_id();
$reference = (string) ($_GET['reference'] ?? '');

$stmt = $pdo->prepare(
    "SELECT ac.* FROM ilearning_addon_charges ac
     JOIN ilearning_addons a ON a.id = ac.addon_id
     WHERE ac.reference = ? AND a.school_id = ? LIMIT 1"
);
$stmt->execute([$reference, $schoolId]);
$charge = $stmt->fetch();

if (!$charge) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => 'Payment request not found.']);
    exit;
}

if ($charge['status'] !== 'pending') {
    echo json_encode([
        'status' => $charge['status'],
        'reference' => $reference,
        'error' => $charge['failure_reason'],
    ]);
    exit;
}

$gateway = Gateways::mobileMoney($charge['network']);
if (!$gateway || empty($charge['provider_reference'])) {
    echo json_encode(['status' => 'pending', 'reference' => $reference]);
    exit;
}

$check = $gateway->checkStatus($charge['provider_reference']);

if ($check['status'] === 'successful') {
    SubscriptionCharge::confirmIfPending(
        $pdo,
        'ilearning_addon_charges',
        (int) $charge['id'],
        function () use ($pdo, $charge): void {
            ilearning_addon_extend_period($pdo, (int) $charge['addon_id'], (string) $charge['plan_code']);
        }
    );

    echo json_encode(['status' => 'successful', 'reference' => $reference]);
} elseif ($check['status'] === 'failed') {
    $reason = $check['error'] ?? 'Payment failed or was declined.';
    SubscriptionCharge::markFailed($pdo, 'ilearning_addon_charges', (int) $charge['id'], $reason);

    echo json_encode(['status' => 'failed', 'reference' => $reference, 'error' => $reason]);
} else {
    echo json_encode(['status' => 'pending', 'reference' => $reference]);
}

function ilearning_addon_extend_period(PDO $pdo, int $addonId, string $planCode): void
{
    $plan = Plans::get($planCode);
    if ($plan === null) {
        return;
    }

    $stmt = $pdo->prepare('SELECT current_period_end FROM ilearning_addons WHERE id = ?');
    $stmt->execute([$addonId]);
    $existingEnd = $stmt->fetchColumn();

    $now = new DateTimeImmutable();
    $start = ($existingEnd && new DateTimeImmutable($existingEnd) > $now) ? new DateTimeImmutable($existingEnd) : $now;
    $end = match ($plan['billing_cycle']) {
        'monthly' => $start->modify('+1 month'),
        'termly' => $start->modify('+4 months'),
        'yearly' => $start->modify('+1 year'),
        default => $start->modify('+4 months'),
    };

    $pdo->prepare(
        "UPDATE ilearning_addons
         SET plan_code = ?, billing_cycle = ?, status = 'active',
             current_period_start = ?, current_period_end = ?, amount = ?, currency = ?
         WHERE id = ?"
    )->execute([
        $planCode,
        $plan['billing_cycle'],
        $now->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        $plan['amount'],
        $plan['currency'],
        $addonId,
    ]);
}

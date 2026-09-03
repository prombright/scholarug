<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — SUBSCRIPTION PAYMENT: STATUS (AJAX, polled from renew.php)
|--------------------------------------------------------------------------
| The ONLY place a subscription payment ever extends the current billing
| period. Guarded by SubscriptionCharge::confirmIfPending()'s
| UPDATE ... WHERE status = 'pending' before acting -- same idempotency
| pattern as bulksms/topup_status.php / bulksms/subscription_status.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/auth_guard.php';
require_role(['headteacher', 'school_admin', 'bursar']);
require_once __DIR__ . '/../payments/Gateways.php';
require_once __DIR__ . '/../payments/SubscriptionCharge.php';
require_once __DIR__ . '/../payments/SubscriptionSync.php';
require_once __DIR__ . '/../payments/Plans.php';

header('Content-Type: application/json');

$schoolId = current_school_id();
$reference = (string) ($_GET['reference'] ?? '');

$stmt = $pdo->prepare(
    "SELECT sc.* FROM subscription_charges sc
     JOIN subscriptions s ON s.id = sc.subscription_id
     WHERE sc.reference = ? AND s.school_id = ? LIMIT 1"
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
        'subscription_charges',
        (int) $charge['id'],
        function () use ($pdo, $charge, $schoolId): void {
            scholar_subscription_extend_period($pdo, (int) $charge['subscription_id'], (string) $charge['plan_code']);
            $_SESSION['subscription_locked'] = scholar_subscription_is_locked($pdo, $schoolId);
        }
    );

    echo json_encode(['status' => 'successful', 'reference' => $reference]);
} elseif ($check['status'] === 'failed') {
    $reason = $check['error'] ?? 'Payment failed or was declined.';
    SubscriptionCharge::markFailed($pdo, 'subscription_charges', (int) $charge['id'], $reason);

    echo json_encode(['status' => 'failed', 'reference' => $reference, 'error' => $reason]);
} else {
    echo json_encode(['status' => 'pending', 'reference' => $reference]);
}

function scholar_subscription_extend_period(PDO $pdo, int $subscriptionId, string $planCode): void
{
    $plan = Plans::get($planCode);
    if ($plan === null) {
        return;
    }

    $stmt = $pdo->prepare('SELECT current_period_end FROM subscriptions WHERE id = ?');
    $stmt->execute([$subscriptionId]);
    $existingEnd = $stmt->fetchColumn();

    $now = new DateTimeImmutable();
    $start = ($existingEnd && new DateTimeImmutable($existingEnd) > $now) ? new DateTimeImmutable($existingEnd) : $now;
    $end = match ($plan['billing_cycle']) {
        'monthly' => $start->modify('+1 month'),
        'termly' => $start->modify('+4 months'),
        'yearly' => $start->modify('+1 year'),
        default => $start->modify('+4 months'),
    };

    $update = $pdo->prepare(
        "UPDATE subscriptions
         SET plan_code = ?, billing_cycle = ?, status = 'active',
             current_period_start = ?, current_period_end = ?, amount = ?, currency = ?
         WHERE id = ?"
    );
    $update->execute([
        $planCode,
        $plan['billing_cycle'],
        $now->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        $plan['amount'],
        $plan['currency'],
        $subscriptionId,
    ]);

    $schoolStmt = $pdo->prepare('SELECT school_id FROM subscriptions WHERE id = ?');
    $schoolStmt->execute([$subscriptionId]);
    $schoolId = (int) $schoolStmt->fetchColumn();

    SubscriptionSync::mirror($pdo, 'scholar', $schoolId, 'active');
}

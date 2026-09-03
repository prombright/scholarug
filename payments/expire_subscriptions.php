<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SUBSCRIPTION EXPIRY SWEEP (CLI, or "Run Now" from devportal/dashboard.php)
|--------------------------------------------------------------------------
| Secondary and NON-authoritative: access control never depends on this
| script running. Each app's own auth_guard.php already checks the
| tenant's subscriptions row live, on every request, which is self-healing
| the instant a payment succeeds. This sweep only exists so admin-list
| pages (medicare/superadmin/organizations.php,
| scholar/developer/schools/schools.php) that read the mirror columns
| show a freshly-flipped 'expired' state without waiting for that
| tenant's next login.
|
| Connects to scholar/medicare directly with their own env-var names
| (SCHOLAR_DB_*, MEDICARE_DB_*) rather than requiring each app's own
| config.php -- those all define the SAME constant names (DB_HOST,
| DB_NAME, ...), so loading more than one in a single process would
| collide. Bulk SMS is not a target here -- it dropped subscriptions
| entirely for per-message wallet billing. Run via:
|   php payments/expire_subscriptions.php
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SubscriptionSync.php';

/** @return array{app:string,pdo:PDO,tenant_table:string,tenant_fk:string} */
function payments_connect_app(string $app, string $envPrefix, string $tenantTable, string $tenantFk): array
{
    $host = getenv("{$envPrefix}_DB_HOST") ?: 'localhost';
    $name = getenv("{$envPrefix}_DB_NAME") ?: $app;
    $user = getenv("{$envPrefix}_DB_USER") ?: 'root';
    $pass = getenv("{$envPrefix}_DB_PASS") ?: '';

    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return ['app' => $app, 'pdo' => $pdo, 'tenant_table' => $tenantTable, 'tenant_fk' => $tenantFk];
}

// Bulk SMS dropped subscriptions entirely (per-message wallet billing
// only, no recurring platform fee) -- no longer a target of this sweep.
$targets = [
    payments_connect_app('scholar', 'SCHOLAR', 'schools', 'school_id'),
    payments_connect_app('medicare', 'MEDICARE', 'organizations', 'org_id'),
];

$graceDays = (int) PAYMENTS_GRACE_PERIOD_DAYS;
$totalExpired = 0;

foreach ($targets as $target) {
    $pdo = $target['pdo'];
    $tenantFk = $target['tenant_fk'];

    // Lapsed = trial_ends_at (while trialing) or current_period_end (while
    // active/past_due) is more than $graceDays in the past. Only rows still
    // in trialing/active/past_due are candidates -- already-expired or
    // canceled rows are left alone.
    $stmt = $pdo->prepare(
        "SELECT id, {$tenantFk} AS tenant_id, status, trial_ends_at, current_period_end
         FROM subscriptions
         WHERE status IN ('trialing', 'active', 'past_due')
           AND (
               (status = 'trialing' AND trial_ends_at IS NOT NULL AND trial_ends_at < DATE_SUB(NOW(), INTERVAL ? DAY))
               OR
               (status IN ('active', 'past_due') AND current_period_end IS NOT NULL AND current_period_end < DATE_SUB(NOW(), INTERVAL ? DAY))
           )"
    );
    $stmt->execute([$graceDays, $graceDays]);
    $lapsed = $stmt->fetchAll();

    foreach ($lapsed as $row) {
        $claim = $pdo->prepare("UPDATE subscriptions SET status = 'expired' WHERE id = ? AND status IN ('trialing','active','past_due')");
        $claim->execute([(int) $row['id']]);

        // Guarded the same way as every other confirm-once path in this
        // codebase: only the run that actually flips the row propagates
        // the mirror-column write, so running this script twice in a row
        // (e.g. an overlapping cron) never double-processes the same tenant.
        if ($claim->rowCount() === 1) {
            SubscriptionSync::mirror($pdo, $target['app'], (int) $row['tenant_id'], 'expired');
            $totalExpired++;
            echo "[{$target['app']}] subscription #{$row['id']} (tenant {$row['tenant_id']}) -> expired\n";
        }
    }
}

echo "Done. {$totalExpired} subscription(s) expired this run.\n";

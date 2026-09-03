<?php
declare(strict_types=1);

/**
 * Keeps each app's pre-existing "mirror" columns (medicare's
 * organizations.payment_status/subscription_start/subscription_end,
 * scholar's schools.payment_status/is_active, bulksms's accounts.status)
 * in lockstep with the new subscriptions table, so existing admin-list
 * pages (medicare/superadmin/organizations.php,
 * scholar/developer/schools/schools.php) keep working unmodified without
 * being rewritten to read `subscriptions` directly. Call this every time a
 * subscriptions row changes -- trial start, payment success, expiry sweep,
 * or a manual admin toggle.
 *
 * This is a phase-1 pragmatic dual-write, not a permanent design: once
 * every read site is migrated to read `subscriptions` directly, the mirror
 * columns and this class can both be retired.
 */
final class SubscriptionSync
{
    public static function mirror(
        PDO $pdo,
        string $app,
        int $tenantId,
        string $subscriptionStatus,
        ?string $periodStart = null,
        ?string $periodEnd = null
    ): void {
        $paymentStatus = match ($subscriptionStatus) {
            'active', 'past_due' => 'paid',
            default => 'pending', // trialing, expired, canceled — mirror enums don't all have 'expired'
        };
        $isActive = in_array($subscriptionStatus, ['trialing', 'active', 'past_due'], true) ? 1 : 0;

        switch ($app) {
            case 'scholar':
                // schools.payment_status is ENUM('pending','paid') only — no 'expired' value exists.
                $stmt = $pdo->prepare('UPDATE schools SET payment_status = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$paymentStatus, $isActive, $tenantId]);
                break;

            case 'medicare':
                // organizations.payment_status DOES support 'expired' — use it directly.
                $orgPaymentStatus = $subscriptionStatus === 'expired' ? 'expired' : $paymentStatus;
                $stmt = $pdo->prepare(
                    'UPDATE organizations
                     SET payment_status = ?, is_active = ?, subscription_start = ?, subscription_end = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $orgPaymentStatus,
                    $isActive,
                    $periodStart !== null ? substr($periodStart, 0, 10) : null,
                    $periodEnd !== null ? substr($periodEnd, 0, 10) : null,
                    $tenantId,
                ]);
                break;

            case 'bulksms':
                $accountStatus = match ($subscriptionStatus) {
                    'trialing' => 'trial',
                    'active', 'past_due' => 'active',
                    default => 'suspended',
                };
                // Never let this dual-write reactivate an account a staff
                // member deliberately suspended — suspension is a distinct,
                // stronger state than a lapsed subscription (see bulksms/auth_guard.php).
                $stmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE id = ? AND status != 'suspended'");
                $stmt->execute([$accountStatus, $tenantId]);
                break;

            default:
                throw new InvalidArgumentException("Unknown app for SubscriptionSync: {$app}");
        }
    }
}

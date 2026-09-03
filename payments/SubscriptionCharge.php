<?php
declare(strict_types=1);

/**
 * Extracts the idempotency pattern bulksms/topup_status.php pioneered for
 * wallet top-ups (a guarded UPDATE ... WHERE status='pending' before ever
 * acting on a "successful" result, so a second concurrent poll can never
 * double-confirm the same charge) into one reusable helper for subscription
 * charges specifically.
 *
 * $table is taken from an in-file whitelist, never from request input, so
 * this stays safe to interpolate into SQL despite PDO not supporting
 * parameterized identifiers.
 */
final class SubscriptionCharge
{
    private const ALLOWED_TABLES = ['subscription_charges', 'ilearning_addon_charges', 'tusome_pass_charges'];

    /**
     * Attempts to claim $chargeId as successful. Only the caller that wins
     * the race (rowCount() === 1) gets $onConfirmed() invoked — a second,
     * later call for the same already-confirmed charge is a safe no-op.
     * Returns true iff this call was the one that confirmed it.
     */
    public static function confirmIfPending(PDO $pdo, string $table, int $chargeId, callable $onConfirmed): bool
    {
        self::assertAllowedTable($table);

        $claim = $pdo->prepare("UPDATE {$table} SET status = 'successful' WHERE id = ? AND status = 'pending'");
        $claim->execute([$chargeId]);

        if ($claim->rowCount() === 1) {
            $onConfirmed();
            return true;
        }

        return false;
    }

    public static function markFailed(PDO $pdo, string $table, int $chargeId, string $reason): void
    {
        self::assertAllowedTable($table);

        $pdo->prepare("UPDATE {$table} SET status = 'failed', failure_reason = ? WHERE id = ? AND status = 'pending'")
            ->execute([$reason, $chargeId]);
    }

    private static function assertAllowedTable(string $table): void
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new InvalidArgumentException("Unknown subscription charge table: {$table}");
        }
    }
}

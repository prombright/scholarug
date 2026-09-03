<?php
declare(strict_types=1);

/**
 * All SMS-wallet balance changes go through here so sms_wallets.balance
 * and the sms_wallet_transactions ledger can never drift apart. Same
 * row-locked adjust() shape as bulksms/lib/Wallet.php, re-scoped to
 * school_id instead of account_id -- each school has its own real
 * balance here, not the one shared platform-wide account
 * bulksms_client.php/message_parents.php use.
 */
final class ScholarSmsWallet
{
    public static function balance(PDO $pdo, int $schoolId): float
    {
        $stmt = $pdo->prepare("SELECT balance FROM sms_wallets WHERE school_id = ?");
        $stmt->execute([$schoolId]);
        $balance = $stmt->fetchColumn();
        return $balance === false ? 0.0 : (float) $balance;
    }

    private static function ensureWallet(PDO $pdo, int $schoolId): void
    {
        $stmt = $pdo->prepare("INSERT IGNORE INTO sms_wallets (school_id, balance) VALUES (?, 0)");
        $stmt->execute([$schoolId]);
    }

    private static function adjust(
        PDO $pdo,
        int $schoolId,
        string $type,
        float $amount,
        ?string $reference,
        ?string $createdBy,
        bool $isCredit
    ): float {
        self::ensureWallet($pdo, $schoolId);

        $pdo->beginTransaction();
        try {
            // Row lock so a top-up and a send on the same school can't
            // race each other into an inconsistent balance.
            $stmt = $pdo->prepare("SELECT balance FROM sms_wallets WHERE school_id = ? FOR UPDATE");
            $stmt->execute([$schoolId]);
            $current = (float) $stmt->fetchColumn();

            $newBalance = $isCredit ? $current + $amount : $current - $amount;
            if (!$isCredit && $newBalance < 0) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            $update = $pdo->prepare("UPDATE sms_wallets SET balance = ? WHERE school_id = ?");
            $update->execute([$newBalance, $schoolId]);

            $log = $pdo->prepare(
                "INSERT INTO sms_wallet_transactions (school_id, type, amount, balance_after, reference, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $log->execute([$schoolId, $type, $amount, $newBalance, $reference, $createdBy ?? 'system']);

            $pdo->commit();
            return $newBalance;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function credit(PDO $pdo, int $schoolId, float $amount, string $type, ?string $reference = null, ?string $createdBy = null): float
    {
        return self::adjust($pdo, $schoolId, $type, $amount, $reference, $createdBy, true);
    }

    /** @throws RuntimeException if the school's wallet can't cover $amount */
    public static function debit(PDO $pdo, int $schoolId, float $amount, string $type, ?string $reference = null, ?string $createdBy = null): float
    {
        return self::adjust($pdo, $schoolId, $type, $amount, $reference, $createdBy, false);
    }
}
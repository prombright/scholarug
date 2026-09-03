<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR HR BULK SMS: WALLET HELPERS
|--------------------------------------------------------------------------
| Shared by the classic hr/sms/wallet.php page and
| scholar/api/hr/sms_wallet.php. Logic ported verbatim from the original
| page. The actual top-up flow (topup_initiate.php/topup_status.php) is
| untouched real-money payment code -- not part of this helper.
*/

function hr_sms_wallet_state(PDO $pdo, int $school_id): array
{
    require_once __DIR__ . '/../../lib/ScholarSmsWallet.php';
    require_once __DIR__ . '/../../lib/ScholarSmsPricing.php';
    require_once __DIR__ . '/../../../payments/Gateways.php';
    require_once __DIR__ . '/../../../payments/CountryCodes.php';

    $balance = ScholarSmsWallet::balance($pdo, $school_id);
    $currency = ScholarSmsPricing::currency($pdo);

    $mtnGateway = Gateways::mobileMoney('mtn');
    $airtelGateway = Gateways::mobileMoney('airtel');

    $tx_stmt = $pdo->prepare("SELECT type, amount, balance_after, reference, created_by, created_at FROM sms_wallet_transactions WHERE school_id = ? ORDER BY created_at DESC LIMIT 50");
    $tx_stmt->execute([$school_id]);
    $transactions = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);

    $pending_stmt = $pdo->prepare("SELECT reference, network, phone, amount, currency, created_at FROM sms_topup_requests WHERE school_id = ? AND status = 'pending' ORDER BY created_at DESC");
    $pending_stmt->execute([$school_id]);
    $pending_topups = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'balance' => $balance,
        'currency' => $currency,
        'mtn_configured' => $mtnGateway->isConfigured(),
        'airtel_configured' => $airtelGateway->isConfigured(),
        'country_codes' => CountryCodes::LIST,
        'transactions' => $transactions,
        'pending_topups' => $pending_topups,
    ];
}

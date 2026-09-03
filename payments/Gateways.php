<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/MobileMoneyGatewayInterface.php';
require_once __DIR__ . '/MtnMomoGateway.php';
require_once __DIR__ . '/AirtelMoneyGateway.php';
require_once __DIR__ . '/StubMomoGateway.php';

/**
 * Resolves 'mtn'/'airtel' into a concrete gateway: the real client if
 * PAYMENTS_MTN_ / PAYMENTS_AIRTEL_ env vars are set, otherwise
 * StubMomoGateway -- same "resolve real, else stub" shape as bulksms's
 * Providers::activeWhatsAppProvider(), just env-var-driven (one ABN
 * merchant account platform-wide) instead of per-account-row-driven.
 */
final class Gateways
{
    public static function mobileMoney(string $network): ?MobileMoneyGatewayInterface
    {
        $real = match ($network) {
            'mtn' => new MtnMomoGateway(),
            'airtel' => new AirtelMoneyGateway(),
            default => null,
        };

        if ($real === null) {
            return null;
        }

        return $real->isConfigured() ? $real : new StubMomoGateway();
    }

    /** True if $network currently resolves to a real (non-demo) gateway. */
    public static function isLive(string $network): bool
    {
        $gateway = self::mobileMoney($network);
        return $gateway !== null && !($gateway instanceof StubMomoGateway);
    }
}

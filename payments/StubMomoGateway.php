<?php
declare(strict_types=1);

require_once __DIR__ . '/MobileMoneyGatewayInterface.php';

/**
 * No real MTN/Airtel merchant credentials exist yet, so every subscription
 * flow (trial, pay, renew, expire) needs to work end-to-end without one --
 * this is the active default until real PAYMENTS_MTN_ / PAYMENTS_AIRTEL_
 * env vars are set (see Gateways::mobileMoney()). Unlike bulksms's
 * StubWhatsAppProvider (a fallback that always reports "not available"),
 * this one has to actually simulate a working gateway, since demoing and
 * testing the whole billing flow today is the point.
 *
 * Outcome is deterministic on the phone number's last digit -- the same
 * "test number" convention real payment processors use -- so both the
 * success and failure path are exercisable on demand, by a human tester or
 * a script, with zero network dependency: suffix '0' simulates a declined
 * payment, anything else succeeds on the first poll. Since checkStatus()
 * only ever receives the provider_reference (not the original phone
 * number), the outcome is decided once in initiate() and encoded into the
 * reference itself so it can be replayed on every subsequent poll.
 */
final class StubMomoGateway implements MobileMoneyGatewayInterface
{
    public function network(): string
    {
        return 'stub';
    }

    public function label(): string
    {
        return 'Mobile Money (Demo — no real charge is made)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function initiate(string $phoneE164, float $amount, string $currency, string $reference): array
    {
        $outcome = substr($phoneE164, -1) === '0' ? 'FAIL' : 'OK';
        return ['status' => 'pending', 'provider_reference' => 'STUB-' . $outcome . '-' . uniqid('', true)];
    }

    public function checkStatus(string $providerReference): array
    {
        if (str_starts_with($providerReference, 'STUB-FAIL-')) {
            return ['status' => 'failed', 'error' => 'Declined by test rule (phone number ending in 0).'];
        }

        return ['status' => 'successful'];
    }
}

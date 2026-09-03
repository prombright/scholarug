<?php
declare(strict_types=1);

/**
 * An interactive "Request to Pay" mobile money gateway (MTN MoMo, Airtel
 * Money). Distinct from bulksms's own PaymentGatewayInterface, which only
 * ever describes a static "here's how to pay us" instruction card (e.g.
 * bank transfer) -- these actually push a payment prompt to the customer's
 * phone and can be polled for a result.
 *
 * Shared across every app (scholar/medicare/bulksms/...) that needs to
 * take a mobile money payment -- wallet top-ups and subscription charges
 * alike -- since the underlying "push a prompt, poll for a result" contract
 * is identical regardless of what the payment is for.
 */
interface MobileMoneyGatewayInterface
{
    /** Machine key -- 'mtn' | 'airtel' | 'stub'. */
    public function network(): string;

    /** Human label shown on the method card, e.g. "MTN Mobile Money". */
    public function label(): string;

    /** False until real API credentials are set via environment variables. */
    public function isConfigured(): bool;

    /**
     * Push a payment prompt to $phoneE164 for $amount $currency, tagged
     * with our own $reference. Returns:
     *   ['status' => 'pending', 'provider_reference' => string]  on success
     *   ['status' => 'error', 'error' => string]                 on failure
     */
    public function initiate(string $phoneE164, float $amount, string $currency, string $reference): array;

    /**
     * Check a previously-initiated request. Returns:
     *   ['status' => 'pending'|'successful'|'failed', 'error' => ?string]
     */
    public function checkStatus(string $providerReference): array;
}

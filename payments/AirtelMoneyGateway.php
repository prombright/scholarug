<?php
declare(strict_types=1);

require_once __DIR__ . '/MobileMoneyGatewayInterface.php';

/**
 * Airtel Money (Airtel Africa Open API) -- Collections "Request to Pay".
 *
 * Built against Airtel's published Collections API contract
 * (https://developers.airtel.africa), but NOT verified against a live
 * sandbox -- there were no credentials available while writing this. Once
 * real PAYMENTS_AIRTEL_* env vars are set, test one real charge and check
 * this class against whatever Airtel actually returns.
 *
 * Flow: OAuth2 client-credentials grant for an access token -> POST a
 * collection request (returns Airtel's own transaction id) -> poll GET
 * for the transaction status. Airtel's status field is parsed loosely
 * (looking for "success"/"fail" rather than one exact code) since the
 * precise enum couldn't be confirmed without a live sandbox -- it only
 * ever reports 'successful' on an unambiguous match, defaulting to
 * 'pending' otherwise so a payment is never falsely marked as failed.
 *
 * Moved here from bulksms/lib/ (formerly credentialed via BULKSMS_AIRTEL_*)
 * so scholar/medicare/bulksms/etc. can all resolve the same real gateway
 * through one ABN merchant account, via Gateways::mobileMoney().
 */
final class AirtelMoneyGateway implements MobileMoneyGatewayInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $country;
    private string $baseUrl;

    public function __construct()
    {
        // Read from the constants payments/config.php already defined
        // (getenv() with fallback happens there, once, same as every other
        // credential in this codebase).
        $this->clientId = defined('PAYMENTS_AIRTEL_CLIENT_ID') ? PAYMENTS_AIRTEL_CLIENT_ID : '';
        $this->clientSecret = defined('PAYMENTS_AIRTEL_CLIENT_SECRET') ? PAYMENTS_AIRTEL_CLIENT_SECRET : '';
        $this->country = defined('PAYMENTS_AIRTEL_COUNTRY') ? PAYMENTS_AIRTEL_COUNTRY : 'UG';
        $this->baseUrl = defined('PAYMENTS_AIRTEL_BASE_URL') ? PAYMENTS_AIRTEL_BASE_URL : 'https://openapiuat.airtel.africa';
    }

    public function network(): string
    {
        return 'airtel';
    }

    public function label(): string
    {
        return 'Airtel Money';
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function initiate(string $phoneE164, float $amount, string $currency, string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Airtel Money is not connected yet.'];
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return ['status' => 'error', 'error' => 'Could not reach Airtel Money right now. Please try again shortly.'];
        }

        // Airtel expects the local subscriber number without the country's
        // leading digits (e.g. "772000000", not "+256772000000").
        $msisdn = preg_replace('/^\+?\d{1,3}/', '', $phoneE164, 1) ?? $phoneE164;

        [$httpCode, $body] = $this->request(
            'POST',
            '/merchant/v1/payments/',
            $token,
            [
                'reference' => $reference,
                'subscriber' => [
                    'country' => $this->country,
                    'currency' => $currency,
                    'msisdn' => $msisdn,
                ],
                'transaction' => [
                    'amount' => $amount,
                    'country' => $this->country,
                    'currency' => $currency,
                    'id' => $reference,
                ],
            ]
        );

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode((string) $body, true);
            $txnId = $data['data']['transaction']['id'] ?? $reference;
            return ['status' => 'pending', 'provider_reference' => (string) $txnId];
        }

        return ['status' => 'error', 'error' => 'Airtel Money declined the request (HTTP ' . $httpCode . ').'];
    }

    public function checkStatus(string $providerReference): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'failed', 'error' => 'Airtel Money is not connected.'];
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return ['status' => 'pending'];
        }

        [$httpCode, $body] = $this->request(
            'GET',
            '/standard/v1/payments/' . rawurlencode($providerReference),
            $token,
            null
        );

        if ($httpCode !== 200) {
            return ['status' => 'pending'];
        }

        $data = json_decode((string) $body, true);
        $providerStatus = strtolower((string) ($data['data']['transaction']['status'] ?? ''));

        if (str_contains($providerStatus, 'success') || $providerStatus === 'ts') {
            return ['status' => 'successful'];
        }
        if (str_contains($providerStatus, 'fail') || $providerStatus === 'tf') {
            return ['status' => 'failed', 'error' => 'Payment failed or was declined.'];
        }

        return ['status' => 'pending'];
    }

    private function getAccessToken(): ?string
    {
        [$httpCode, $body] = $this->request(
            'POST',
            '/auth/oauth2/token',
            null,
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]
        );

        if ($httpCode !== 200) {
            return null;
        }

        $data = json_decode((string) $body, true);
        return is_string($data['access_token'] ?? null) ? $data['access_token'] : null;
    }

    /** @return array{0:int,1:string} [httpStatusCode, rawResponseBody] */
    private function request(string $method, string $path, ?string $bearerToken, ?array $jsonBody): array
    {
        $headers = ['Content-Type: application/json', 'Accept: */*'];
        if ($bearerToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
            $headers[] = 'X-Country: ' . $this->country;
            $headers[] = 'X-Currency: UGX';
        }

        $ch = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ];
        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            error_log('AirtelMoneyGateway request failed: ' . curl_error($ch));
            curl_close($ch);
            return [0, ''];
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$httpCode, (string) $response];
    }
}

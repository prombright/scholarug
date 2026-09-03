<?php
declare(strict_types=1);

require_once __DIR__ . '/MobileMoneyGatewayInterface.php';

/**
 * MTN Mobile Money Open API -- Collections "Request to Pay".
 *
 * Built against MTN's published Collections API contract
 * (https://momodeveloper.mtn.com), but NOT verified against a live sandbox
 * -- there were no credentials available while writing this. Once real
 * PAYMENTS_MTN_* env vars are set, test one real charge and check this
 * class against whatever MTN actually returns; the request/response shape
 * occasionally drifts between API versions/regions.
 *
 * Flow: get an access token (Basic auth with the API user/key pair) ->
 * POST requesttopay (fire-and-forget, 202 Accepted means "prompt sent") ->
 * poll GET requesttopay/{id} for SUCCESSFUL/FAILED/PENDING.
 *
 * Moved here from bulksms/lib/ (formerly credentialed via BULKSMS_MTN_*)
 * so scholar/medicare/bulksms/etc. can all resolve the same real gateway
 * through one ABN merchant account, via Gateways::mobileMoney().
 */
final class MtnMomoGateway implements MobileMoneyGatewayInterface
{
    private string $subscriptionKey;
    private string $apiUser;
    private string $apiKey;
    private string $targetEnvironment;
    private string $baseUrl;

    public function __construct()
    {
        // Read from the constants payments/config.php already defined
        // (getenv() with fallback happens there, once, same as every other
        // credential in this codebase).
        $this->subscriptionKey = defined('PAYMENTS_MTN_SUBSCRIPTION_KEY') ? PAYMENTS_MTN_SUBSCRIPTION_KEY : '';
        $this->apiUser = defined('PAYMENTS_MTN_API_USER') ? PAYMENTS_MTN_API_USER : '';
        $this->apiKey = defined('PAYMENTS_MTN_API_KEY') ? PAYMENTS_MTN_API_KEY : '';
        $this->targetEnvironment = defined('PAYMENTS_MTN_TARGET_ENV') ? PAYMENTS_MTN_TARGET_ENV : 'sandbox';
        $this->baseUrl = defined('PAYMENTS_MTN_BASE_URL') ? PAYMENTS_MTN_BASE_URL : 'https://sandbox.momodeveloper.mtn.com';
    }

    public function network(): string
    {
        return 'mtn';
    }

    public function label(): string
    {
        return 'MTN Mobile Money';
    }

    public function isConfigured(): bool
    {
        return $this->subscriptionKey !== '' && $this->apiUser !== '' && $this->apiKey !== '';
    }

    public function initiate(string $phoneE164, float $amount, string $currency, string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'MTN Mobile Money is not connected yet.'];
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return ['status' => 'error', 'error' => 'Could not reach MTN Mobile Money right now. Please try again shortly.'];
        }

        $referenceId = $this->uuidV4();
        $msisdn = ltrim($phoneE164, '+');

        [$httpCode, ] = $this->request(
            'POST',
            '/collection/v1_0/requesttopay',
            [
                'Authorization: Bearer ' . $token,
                'X-Reference-Id: ' . $referenceId,
                'X-Target-Environment: ' . $this->targetEnvironment,
                'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
                'Content-Type: application/json',
            ],
            [
                'amount' => number_format($amount, 0, '.', ''),
                'currency' => $currency,
                'externalId' => $reference,
                'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $msisdn],
                'payerMessage' => 'ScholarUg payment',
                'payeeNote' => $reference,
            ]
        );

        if ($httpCode === 202) {
            return ['status' => 'pending', 'provider_reference' => $referenceId];
        }

        return ['status' => 'error', 'error' => 'MTN Mobile Money declined the request (HTTP ' . $httpCode . ').'];
    }

    public function checkStatus(string $providerReference): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'failed', 'error' => 'MTN Mobile Money is not connected.'];
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            // Transient -- report pending so the caller's poll loop retries
            // instead of prematurely marking a real payment as failed.
            return ['status' => 'pending'];
        }

        [$httpCode, $body] = $this->request(
            'GET',
            '/collection/v1_0/requesttopay/' . rawurlencode($providerReference),
            [
                'Authorization: Bearer ' . $token,
                'X-Target-Environment: ' . $this->targetEnvironment,
                'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            ],
            null
        );

        if ($httpCode !== 200) {
            return ['status' => 'pending'];
        }

        $data = json_decode((string) $body, true);
        $providerStatus = strtoupper((string) ($data['status'] ?? ''));

        return match ($providerStatus) {
            'SUCCESSFUL' => ['status' => 'successful'],
            'FAILED' => ['status' => 'failed', 'error' => (string) ($data['reason'] ?? 'Payment failed or was declined.')],
            default => ['status' => 'pending'],
        };
    }

    private function getAccessToken(): ?string
    {
        $auth = base64_encode($this->apiUser . ':' . $this->apiKey);
        [$httpCode, $body] = $this->request(
            'POST',
            '/collection/token/',
            [
                'Authorization: Basic ' . $auth,
                'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            ],
            null
        );

        if ($httpCode !== 200) {
            return null;
        }

        $data = json_decode((string) $body, true);
        return is_string($data['access_token'] ?? null) ? $data['access_token'] : null;
    }

    /** @return array{0:int,1:string} [httpStatusCode, rawResponseBody] */
    private function request(string $method, string $path, array $headers, ?array $jsonBody): array
    {
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
            error_log('MtnMomoGateway request failed: ' . curl_error($ch));
            curl_close($ch);
            return [0, ''];
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$httpCode, (string) $response];
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

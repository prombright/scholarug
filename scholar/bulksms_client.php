<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR — ABN BULK SMS CLIENT
|--------------------------------------------------------------------------
| Thin wrapper around bulksms/api/send.php, the same raw-cURL pattern
| bulksms/lib/AiAssistant.php uses (no Composer/SDK anywhere in this
| project). Scholar has its own wallet inside Bulk SMS (the "Scholar"
| service account) — every send here debits that wallet, funded the same
| way any Bulk SMS customer's wallet is funded.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';

final class BulkSmsClient
{
    /**
     * @param string[] $phones
     * @return array{campaign_id:int, segments:int, total_cost:float, currency:string, delivered:int, failed:int}
     * @throws RuntimeException if not configured, the call fails, or the API returns an error
     */
    public static function send(string $message, array $phones): array
    {
        if (BULKSMS_API_KEY === '') {
            throw new RuntimeException(
                'Bulk SMS is not connected yet. Set SCHOLAR_BULKSMS_API_KEY (generate it from the '
                . 'Bulk SMS admin panel: Accounts -> Scholar -> generate API key).'
            );
        }

        $ch = curl_init(BULKSMS_API_URL . '/send.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . BULKSMS_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['message' => $message, 'phones' => $phones], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
        ]);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Could not reach Bulk SMS: ' . $curlError);
        }

        $data = json_decode($responseBody, true);

        if ($httpStatus !== 200 || ($data['status'] ?? null) !== 'success') {
            throw new RuntimeException('Bulk SMS error: ' . ($data['message'] ?? ('HTTP ' . $httpStatus)));
        }

        return $data['data'];
    }
}

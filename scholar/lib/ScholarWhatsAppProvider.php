<?php
declare(strict_types=1);

/**
 * WhatsApp is a best-effort fallback channel, not a guaranteed one --
 * there is no reliable free API to check whether a number *has* WhatsApp
 * before sending. "Attempt WhatsApp, fall back to SMS on failure" is the
 * real pattern -- this interface models exactly that single attempt.
 * Copied from bulksms/lib/WhatsAppProviderInterface.php.
 */
interface ScholarWhatsAppProviderInterface
{
    public function name(): string;

    /** @return array{status: 'sent'|'not_available', provider_message_id: ?string} */
    public function attemptSend(string $phone, string $message): array;
}

/**
 * No Meta WhatsApp Business Cloud API app is connected for this school
 * yet, so every attempt reports 'not_available' -- ScholarCampaignSender
 * falls back to SMS every time. Copied from bulksms/lib/StubWhatsAppProvider.php.
 */
final class ScholarStubWhatsAppProvider implements ScholarWhatsAppProviderInterface
{
    public function name(): string
    {
        return 'WhatsApp (not connected — falls back to SMS)';
    }

    public function attemptSend(string $phone, string $message): array
    {
        return ['status' => 'not_available', 'provider_message_id' => null];
    }
}

/**
 * Meta WhatsApp Business Cloud API — one instance per school, constructed
 * with that school's own Phone Number ID + access token (each school
 * sends as its own registered WhatsApp Business number). Copied from
 * bulksms/lib/MetaWhatsAppProvider.php (identical Cloud API contract,
 * same "not verified against a live app yet" caveat -- once a real
 * school connects real credentials via whatsapp_settings.php, test one
 * real send and check this class against whatever Meta actually
 * returns).
 *
 * Important limitation, not solvable in code: Meta only allows free-form
 * text outside Meta-approved templates within a 24-hour window after the
 * *recipient* has messaged the business number first. A send outside
 * that window comes back as a Meta API error and this provider correctly
 * reports 'not_available', so the send falls back to SMS -- the failure
 * is visible, not silent.
 */
final class ScholarMetaWhatsAppProvider implements ScholarWhatsAppProviderInterface
{
    private string $phoneNumberId;
    private string $accessToken;
    private string $senderName;
    private string $baseUrl;

    public function __construct(string $phoneNumberId, string $accessToken, string $senderName)
    {
        $this->phoneNumberId = $phoneNumberId;
        $this->accessToken = $accessToken;
        $this->senderName = $senderName;
        $this->baseUrl = 'https://graph.facebook.com/v19.0';
    }

    public function name(): string
    {
        return 'WhatsApp — ' . $this->senderName;
    }

    public function attemptSend(string $phone, string $message): array
    {
        $to = ltrim($phone, '+');

        $ch = curl_init($this->baseUrl . '/' . rawurlencode($this->phoneNumberId) . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $message],
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            error_log('ScholarMetaWhatsAppProvider request failed: ' . curl_error($ch));
            curl_close($ch);
            return ['status' => 'not_available', 'provider_message_id' => null];
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['status' => 'not_available', 'provider_message_id' => null];
        }

        $data = json_decode((string) $response, true);
        $messageId = $data['messages'][0]['id'] ?? null;

        if (!is_string($messageId)) {
            return ['status' => 'not_available', 'provider_message_id' => null];
        }

        return ['status' => 'sent', 'provider_message_id' => $messageId];
    }
}
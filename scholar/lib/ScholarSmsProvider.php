<?php
declare(strict_types=1);

require_once __DIR__ . '/ScholarWhatsAppProvider.php';
require_once __DIR__ . '/ScholarSecrets.php';

/**
 * Swappable SMS delivery, same "resolve real, else stub" shape as
 * bulksms/lib/Providers.php::activeSmsProvider(). Right now this always
 * returns the stub -- wiring a real gateway (Africa's Talking, EgoSMS,
 * etc.) later means adding one class implementing this interface and
 * one line in activeProvider() below, nothing else in the module
 * changes.
 */
interface ScholarSmsProviderInterface
{
    /** @return array{status: 'delivered'|'failed', provider_message_id: ?string} */
    public function send(string $phone, string $message): array;

    public function isSimulated(): bool;
}

/**
 * Always "succeeds" -- no network call, no real SMS reaches any phone.
 * Every recipient status in the UI must stay honestly labeled as
 * simulated while this is the active provider (see history.php).
 */
final class ScholarTestSmsProvider implements ScholarSmsProviderInterface
{
    public function send(string $phone, string $message): array
    {
        return [
            'status' => 'delivered',
            'provider_message_id' => 'TEST-' . strtoupper(bin2hex(random_bytes(6))),
        ];
    }

    public function isSimulated(): bool
    {
        return true;
    }
}

final class ScholarSmsProviders
{
    public static function activeProvider(): ScholarSmsProviderInterface
    {
        return new ScholarTestSmsProvider();
    }

    /**
     * WhatsApp senders are per-school (each school connects its own
     * WhatsApp Business number via hr/sms/whatsapp_settings.php) --
     * resolves from that school's sms_whatsapp_settings row.
     */
    public static function activeWhatsAppProvider(PDO $pdo, int $schoolId): ScholarWhatsAppProviderInterface
    {
        $stmt = $pdo->prepare(
            "SELECT sender_name, phone_number_id, access_token_encrypted
             FROM sms_whatsapp_settings WHERE school_id = ? AND status = 'active'"
        );
        $stmt->execute([$schoolId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return new ScholarStubWhatsAppProvider();
        }

        $accessToken = ScholarSecrets::decrypt((string) $row['access_token_encrypted']);
        if ($accessToken === null) {
            return new ScholarStubWhatsAppProvider();
        }

        return new ScholarMetaWhatsAppProvider((string) $row['phone_number_id'], $accessToken, (string) $row['sender_name']);
    }

    public static function whatsAppConfigured(PDO $pdo, int $schoolId): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sms_whatsapp_settings WHERE school_id = ? AND status = 'active'");
        $stmt->execute([$schoolId]);
        return $stmt->fetchColumn() !== false;
    }
}
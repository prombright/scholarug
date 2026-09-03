<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SCHOLAR HR BULK SMS: WHATSAPP SETTINGS HELPERS
|--------------------------------------------------------------------------
| Shared by the classic hr/sms/whatsapp_settings.php page and
| scholar/api/hr/sms_whatsapp.php. Logic ported verbatim from the original
| page.
*/

function hr_sms_whatsapp_settings(PDO $pdo, int $school_id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT sender_name, whatsapp_number, phone_number_id, status
         FROM sms_whatsapp_settings WHERE school_id = ?"
    );
    $stmt->execute([$school_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hr_sms_whatsapp_disable(PDO $pdo, int $school_id): array
{
    $pdo->prepare("UPDATE sms_whatsapp_settings SET status = 'disabled' WHERE school_id = ?")->execute([$school_id]);
    return ['ok' => true, 'message' => 'WhatsApp disconnected. Sends will use SMS only.'];
}

function hr_sms_whatsapp_save(PDO $pdo, int $school_id, ?array $existing, string $sender_name, string $whatsapp_number, string $phone_number_id, string $access_token): array
{
    require_once __DIR__ . '/../../lib/ScholarSecrets.php';

    if ($sender_name === '' || $whatsapp_number === '' || $phone_number_id === '') {
        return ['ok' => false, 'message' => 'Sender name, WhatsApp number, and Phone Number ID are all required.'];
    }
    if ($access_token === '' && $existing === null) {
        return ['ok' => false, 'message' => 'Access token is required the first time you connect.'];
    }

    if ($access_token !== '') {
        $encrypted = ScholarSecrets::encrypt($access_token);
        $upsert = $pdo->prepare(
            "INSERT INTO sms_whatsapp_settings (school_id, sender_name, whatsapp_number, phone_number_id, access_token_encrypted, status)
             VALUES (?, ?, ?, ?, ?, 'active')
             ON DUPLICATE KEY UPDATE sender_name = VALUES(sender_name), whatsapp_number = VALUES(whatsapp_number),
                 phone_number_id = VALUES(phone_number_id), access_token_encrypted = VALUES(access_token_encrypted), status = 'active'"
        );
        $upsert->execute([$school_id, $sender_name, $whatsapp_number, $phone_number_id, $encrypted]);
    } else {
        // Editing details without replacing an already-saved token.
        $update = $pdo->prepare(
            "UPDATE sms_whatsapp_settings SET sender_name = ?, whatsapp_number = ?, phone_number_id = ?, status = 'active' WHERE school_id = ?"
        );
        $update->execute([$sender_name, $whatsapp_number, $phone_number_id, $school_id]);
    }

    return ['ok' => true, 'message' => 'WhatsApp settings saved. New sends will attempt WhatsApp first.'];
}

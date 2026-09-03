<?php
declare(strict_types=1);

require_once __DIR__ . '/ScholarSmsPricing.php';
require_once __DIR__ . '/ScholarSmsWallet.php';
require_once __DIR__ . '/ScholarSmsProvider.php';

/**
 * The one place a Scholar-embedded bulk send actually happens -- mirrors
 * bulksms/lib/CampaignSender.php's "debit first, send never happens
 * without payment" shape. For a school with WhatsApp connected
 * (hr/sms/whatsapp_settings.php), every recipient gets a WhatsApp
 * attempt first, falling back to SMS automatically when it's not
 * available for that number -- same "attempt, don't guess" pattern
 * bulksms uses, and the WhatsApp attempt rides along at no extra charge
 * (pricing is still computed on the SMS segment count -- see
 * ScholarSmsPricing::totalCost() below).
 */
final class ScholarCampaignSender
{
    /**
     * @param array<int, array{full_name: ?string, phone: string}> $recipients
     * @return array{campaign_id:int, segments:int, total_cost:float, currency:string, delivered:int, failed:int, simulated:bool}
     * @throws RuntimeException if the message is empty, there are no recipients, or the wallet can't cover the cost
     */
    public static function send(PDO $pdo, int $schoolId, int $userId, string $message, array $recipients): array
    {
        $message = trim($message);
        // Same phone can't appear twice even if two different sources
        // both surfaced it -- ScholarSmsContacts::resolveGroup() already
        // dedupes, this is just a second guard for imported lists.
        $seen = [];
        $clean = [];
        foreach ($recipients as $r) {
            $phone = trim((string) $r['phone']);
            if ($phone === '' || isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $clean[] = ['full_name' => $r['full_name'] ?? null, 'phone' => $phone];
        }

        if ($message === '') {
            throw new RuntimeException('Message cannot be empty.');
        }
        if (count($clean) === 0) {
            throw new RuntimeException('At least one recipient is required.');
        }

        $info = ScholarSmsPricing::segmentInfo($message);
        $segments = max(1, $info['segments']);
        $currency = ScholarSmsPricing::currency($pdo);
        $totalCost = ScholarSmsPricing::totalCost($pdo, $segments, count($clean));

        // Debits first -- send never happens without payment.
        // ScholarSmsWallet::debit() manages its own transaction/row-lock,
        // so it must not be nested inside another beginTransaction() here.
        ScholarSmsWallet::debit($pdo, $schoolId, $totalCost, 'send_debit', 'Bulk send: ' . count($clean) . ' recipient(s)', (string) $userId);

        try {
            $insertCampaign = $pdo->prepare(
                "INSERT INTO sms_campaigns (school_id, message, segments, recipient_count, total_cost, status, created_by)
                 VALUES (?, ?, ?, ?, ?, 'queued', ?)"
            );
            $insertCampaign->execute([$schoolId, $message, $segments, count($clean), $totalCost, $userId]);
            $campaignId = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            // The debit already went through -- refund it so a failed
            // campaign row never leaves money silently deducted.
            ScholarSmsWallet::credit($pdo, $schoolId, $totalCost, 'refund', 'Refund: campaign creation failed', (string) $userId);
            throw $e;
        }

        $provider = ScholarSmsProviders::activeProvider();
        $whatsAppProvider = ScholarSmsProviders::whatsAppConfigured($pdo, $schoolId)
            ? ScholarSmsProviders::activeWhatsAppProvider($pdo, $schoolId)
            : null;
        $delivered = 0;
        $failed = 0;

        $insertRecipient = $pdo->prepare(
            "INSERT INTO sms_campaign_recipients (campaign_id, phone, channel, full_name, delivery_status, provider_message_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($clean as $r) {
            $channel = 'sms';
            $result = null;

            if ($whatsAppProvider !== null) {
                $waResult = $whatsAppProvider->attemptSend($r['phone'], $message);
                if ($waResult['status'] === 'sent') {
                    $channel = 'whatsapp';
                    $result = ['status' => 'delivered', 'provider_message_id' => $waResult['provider_message_id']];
                }
            }

            if ($result === null) {
                $result = $provider->send($r['phone'], $message);
            }

            $status = $result['status'] === 'delivered' ? 'delivered' : 'failed';
            $status === 'delivered' ? $delivered++ : $failed++;

            $insertRecipient->execute([$campaignId, $r['phone'], $channel, $r['full_name'], $status, $result['provider_message_id'] ?? null]);
        }

        $finalStatus = $failed === 0 ? 'sent' : ($delivered === 0 ? 'failed' : 'sent');
        $pdo->prepare("UPDATE sms_campaigns SET status = ? WHERE id = ?")->execute([$finalStatus, $campaignId]);

        return [
            'campaign_id' => $campaignId,
            'segments' => $segments,
            'total_cost' => $totalCost,
            'currency' => $currency,
            'delivered' => $delivered,
            'failed' => $failed,
            'simulated' => $provider->isSimulated(),
        ];
    }
}

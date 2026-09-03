<?php
declare(strict_types=1);

/**
 * SMS segment/cost math -- same GSM-7 segment-counting rules as
 * bulksms/lib/Pricing.php (standard SMS billing behaviour, not
 * ABN-specific), but a single flat platform-wide rate
 * (sms_pricing_settings) instead of bulksms's volume-discount tiers --
 * deliberately simpler for this embedded v1. Kept in one place so the
 * compose preview and the actual debit at send time never disagree.
 */
final class ScholarSmsPricing
{
    // GSM-7 basic charset (default alphabet). Anything outside this set
    // forces UCS-2 encoding, which halves the per-segment character
    // budget -- standard SMS billing behaviour.
    private const GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    public static function isGsm7(string $message): bool
    {
        $len = mb_strlen($message, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($message, $i, 1, 'UTF-8');
            if (mb_strpos(self::GSM7, $char, 0, 'UTF-8') === false) {
                return false;
            }
        }
        return true;
    }

    /** @return array{segments:int, encoding:string, chars_per_segment:int, length:int} */
    public static function segmentInfo(string $message): array
    {
        $length = mb_strlen($message, 'UTF-8');
        $gsm7 = self::isGsm7($message);
        $singleLimit = $gsm7 ? 160 : 70;
        $multiLimit = $gsm7 ? 153 : 67;

        if ($length === 0) {
            $segments = 0;
        } elseif ($length <= $singleLimit) {
            $segments = 1;
        } else {
            $segments = (int) ceil($length / $multiLimit);
        }

        return [
            'segments' => $segments,
            'encoding' => $gsm7 ? 'GSM-7' : 'UCS-2',
            'chars_per_segment' => $segments <= 1 ? $singleLimit : $multiLimit,
            'length' => $length,
        ];
    }

    public static function ratePerSegment(PDO $pdo): float
    {
        $stmt = $pdo->query("SELECT price_per_segment FROM sms_pricing_settings WHERE id = 1");
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    public static function currency(PDO $pdo): string
    {
        $stmt = $pdo->query("SELECT currency FROM sms_pricing_settings WHERE id = 1");
        return (string) ($stmt->fetchColumn() ?: 'UGX');
    }

    public static function totalCost(PDO $pdo, int $segments, int $recipientCount): float
    {
        return round(self::ratePerSegment($pdo) * $segments * $recipientCount, 2);
    }
}
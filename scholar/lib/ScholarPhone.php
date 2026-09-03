<?php
declare(strict_types=1);

/**
 * Copied from bulksms/lib/Phone.php (self-contained, no dependencies).
 * Shared by contacts.php's Excel/CSV import so the same number never ends
 * up saved twice in two different formats (e.g. "0771234567" and
 * "+256771234567" as separate contacts).
 */
final class ScholarPhone
{
    /** Best-effort Uganda-friendly normalization: 0771234567 -> +256771234567. */
    public static function normalize(string $raw): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($raw));
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (strpos($digits, '+') === 0) {
            return $digits;
        }
        if (strpos($digits, '256') === 0) {
            return '+' . $digits;
        }
        if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
            return '+256' . substr($digits, 1);
        }
        if (ctype_digit($digits) && strlen($digits) >= 9) {
            return '+' . $digits;
        }
        return null;
    }
}
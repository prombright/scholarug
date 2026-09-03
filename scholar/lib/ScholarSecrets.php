<?php
declare(strict_types=1);

/**
 * Encrypts/decrypts sensitive per-school credentials (Meta WhatsApp
 * access tokens) before they touch the database, using libsodium's
 * secretbox (bundled with PHP 8, no extension install needed). Copied
 * from bulksms/lib/Secrets.php, own key/env-var so it's independent of
 * bulksms's key material.
 *
 * Key resolution: SCHOLAR_CREDENTIAL_KEY env var (base64) if set,
 * matching every other credential's env-var-first pattern in this app
 * (config.php). Otherwise a random key is generated once into a local
 * gitignored file (scholar/_secrets/credential.key) so local dev works
 * with zero setup.
 */
final class ScholarSecrets
{
    private static ?string $key = null;

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::key());
        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $envKey = getenv('SCHOLAR_CREDENTIAL_KEY') ?: '';
        if ($envKey !== '') {
            $decoded = base64_decode($envKey, true);
            if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                self::$key = $decoded;
                return self::$key;
            }
        }

        $dir = __DIR__ . '/../_secrets';
        $path = $dir . '/credential.key';

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        if (is_file($path)) {
            $key = file_get_contents($path);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                self::$key = $key;
                return self::$key;
            }
        }

        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        file_put_contents($path, $key);
        chmod($path, 0600);
        self::$key = $key;
        return self::$key;
    }
}
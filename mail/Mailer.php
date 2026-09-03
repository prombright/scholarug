<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MINIMAL SMTP CLIENT (STARTTLS + AUTH LOGIN)
|--------------------------------------------------------------------------
| No composer.json/vendor exists anywhere in this repo -- rather than
| fetch and trust an unpackaged ~4000-line third-party library (PHPMailer)
| with nothing to track its version, this is a small first-party client
| covering exactly what Gmail's SMTP (port 587, STARTTLS, AUTH LOGIN)
| needs. Raw stream sockets, no external dependency.
|--------------------------------------------------------------------------
*/

class AbnMailer
{
    private $lastError = '';

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        if (SMTP_PASS === '') {
            $this->lastError = 'SMTP not configured (SMTP_PASS is empty) -- see mail/config.local.php.example';
            return false;
        }

        $errno = 0;
        $errstr = '';
        // Some shared mail clusters terminate TLS with a cert CN that
        // doesn't match SMTP_HOST (one cert shared across reseller
        // domains) -- SMTP_TLS_PEER_NAME lets config.local.php point
        // verification at the cert's real name instead of SMTP_HOST.
        $peerName = defined('SMTP_TLS_PEER_NAME') && SMTP_TLS_PEER_NAME !== '' ? SMTP_TLS_PEER_NAME : SMTP_HOST;
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $peerName,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            'tcp://' . SMTP_HOST . ':' . SMTP_PORT,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            $this->lastError = "Could not connect to SMTP host: {$errstr} ({$errno})";
            return false;
        }
        stream_set_timeout($socket, 15);

        try {
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO ' . SMTP_HOST, 250);
            $this->command($socket, 'STARTTLS', 220);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->lastError = 'STARTTLS negotiation failed';
                return false;
            }

            // Re-EHLO is required after STARTTLS -- the pre-TLS EHLO's
            // capability list (including AUTH) is discarded per RFC 3207.
            $this->command($socket, 'EHLO ' . SMTP_HOST, 250);
            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode(SMTP_USER), 334);
            $this->command($socket, base64_encode(SMTP_PASS), 235);

            $this->command($socket, 'MAIL FROM:<' . SMTP_USER . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', 250);
            $this->command($socket, 'DATA', 354);

            // Both missing on every email this ever sent -- no Message-ID
            // (RFC 5322 requires one; its absence alone is enough for some
            // filters, Gmail included, to weight a message toward spam) and
            // HTML-only with no text/plain part (a near-universal signal of
            // bulk/marketing mail vs. genuine transactional mail, which
            // legitimate senders almost always send as multipart/alternative).
            $domain = substr(strrchr(SMTP_FROM_EMAIL, '@'), 1) ?: SMTP_HOST;
            $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
            $plainBody = trim(html_entity_decode(strip_tags(preg_replace('/<(br|p|div|li)[^>]*>/i', "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));

            $boundary = 'abn-' . bin2hex(random_bytes(12));

            $headers = [
                'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
                'To: <' . $to . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'Date: ' . date('r'),
                'Message-ID: ' . $messageId,
            ];

            $bodyParts =
                '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $plainBody . "\r\n\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n\r\n"
                . '--' . $boundary . '--';

            // Dot-stuffing (RFC 5321 4.5.2): any line starting with '.'
            // must be escaped to '..' or the SMTP server treats a lone
            // '.' line as end-of-DATA and truncates the message there.
            $stuffedBody = preg_replace('/^\./m', '..', $bodyParts);

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $stuffedBody . "\r\n.";
            fwrite($socket, $message . "\r\n");
            $this->expect($socket, 250);

            $this->command($socket, 'QUIT', 221);
            return true;
        } catch (RuntimeException $e) {
            $this->lastError = $e->getMessage();
            return false;
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function command($socket, string $line, int $expectedCode): string
    {
        fwrite($socket, $line . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    /** @param resource $socket */
    private function expect($socket, int $expectedCode): string
    {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // A hyphen after the 3-digit code means the response
            // continues on another line; a space means this was the last.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("SMTP error: expected {$expectedCode}, got: " . trim($response));
        }
        return $response;
    }

    private function encodeHeader(string $text): string
    {
        // RFC 2047 encoded-word, needed the moment a subject has anything
        // outside plain ASCII (unlikely here, but cheap to handle right).
        if (preg_match('/[^\x20-\x7E]/', $text) === 1) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        return $text;
    }
}

function abn_send_email(string $to, string $subject, string $htmlBody): bool
{
    static $mailer = null;
    if ($mailer === null) {
        $mailer = new AbnMailer();
    }
    $ok = $mailer->send($to, $subject, $htmlBody);
    if (!$ok) {
        error_log('abn_send_email failed for ' . $to . ': ' . $mailer->getLastError());
    }
    return $ok;
}

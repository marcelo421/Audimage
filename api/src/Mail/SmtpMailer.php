<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Minimal SMTP client (STARTTLS + AUTH LOGIN) using raw sockets — no
 * external dependency (no PHPMailer/Symfony Mailer required). Sufficient
 * for a low-volume transactional flow like email verification.
 *
 * For anything beyond that (bounce handling, retries, deliverability
 * tooling), prefer ResendMailer or swap this for a maintained library.
 */
class SmtpMailer implements MailerInterface
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private int $timeoutSeconds;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName = 'AUDIMAGE',
        int $timeoutSeconds = 15
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeoutSeconds);
        if ($socket === false) {
            error_log("SmtpMailer: connect failed to {$this->host}:{$this->port} — {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            $this->expect($socket, '220');
            $this->command($socket, 'EHLO ' . gethostname(), '250');

            if ($this->port === 587 || $this->port === 25) {
                $this->command($socket, 'STARTTLS', '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    error_log('SmtpMailer: STARTTLS negotiation failed');
                    return false;
                }
                // Must re-EHLO after upgrading to TLS.
                $this->command($socket, 'EHLO ' . gethostname(), '250');
            }

            $this->command($socket, 'AUTH LOGIN', '334');
            $this->command($socket, base64_encode($this->username), '334');
            $this->command($socket, base64_encode($this->password), '235');

            $this->command($socket, 'MAIL FROM:<' . $this->fromEmail . '>', '250');
            $this->command($socket, 'RCPT TO:<' . $to . '>', '250');
            $this->command($socket, 'DATA', '354');

            $headers = $this->buildHeaders($to, $subject);
            $body = $this->escapeDotStuffing($htmlBody);

            fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
            $this->expect($socket, '250');

            $this->command($socket, 'QUIT', '221');

            return true;
        } catch (\RuntimeException $e) {
            error_log('SmtpMailer: ' . $e->getMessage());
            return false;
        } finally {
            fclose($socket);
        }
    }

    private function buildHeaders(string $to, string $subject): string
    {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromHeader = sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($this->fromName), $this->fromEmail);

        return implode("\r\n", [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]) . "\r\n";
    }

    /** Lines starting with "." must be escaped per RFC 5321 before the DATA terminator. */
    private function escapeDotStuffing(string $body): string
    {
        return preg_replace('/^\./m', '..', $body);
    }

    /**
     * @param resource $socket
     */
    private function command($socket, string $line, string $expectedCode): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expectedCode);
    }

    /**
     * @param resource $socket
     */
    private function expect($socket, string $expectedCode): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // Multi-line SMTP responses use "code-" on all but the last line.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if (strpos($response, $expectedCode) !== 0) {
            throw new \RuntimeException("Unexpected SMTP response (expected {$expectedCode}): {$response}");
        }
    }
}

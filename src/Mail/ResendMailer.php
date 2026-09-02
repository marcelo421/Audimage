<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Sends via the Resend HTTP API (https://resend.com/docs/api-reference/emails/send-email).
 * No SDK dependency — a single authenticated POST is all this needs.
 */
class ResendMailer implements MailerInterface
{
    private const API_URL = 'https://api.resend.com/emails';

    private string $apiKey;
    private string $fromEmail;
    private string $fromName;

    public function __construct(string $apiKey, string $fromEmail, string $fromName = 'AUDIMAGE')
    {
        $this->apiKey = $apiKey;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $payload = json_encode([
            'from' => sprintf('%s <%s>', $this->fromName, $this->fromEmail),
            'to' => [$to],
            'subject' => $subject,
            'html' => $htmlBody,
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('ResendMailer: request failed — ' . $error);
            return false;
        }

        if ($status < 200 || $status >= 300) {
            error_log('ResendMailer: non-2xx response (' . $status . '): ' . $response);
            return false;
        }

        return true;
    }
}

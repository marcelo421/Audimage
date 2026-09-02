<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Default mailer for local dev/testing: writes the email to a file instead
 * of sending it. Lets you develop and test the full verification flow
 * (including clicking the link) without any SMTP/API credentials.
 *
 * Never use MAIL_DRIVER=log in production — set MAIL_DRIVER=smtp or
 * MAIL_DRIVER=resend instead.
 */
class LogMailer implements MailerInterface
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? sys_get_temp_dir() . '/audimage_mail.log';
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $entry = sprintf(
            "==== %s ====\nTo: %s\nSubject: %s\n\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $htmlBody
        );

        return @file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }
}

<?php

declare(strict_types=1);

namespace App\Mail;

class MailerFactory
{
    public static function createFromEnv(): MailerInterface
    {
        $driver = strtolower((string)(getenv('MAIL_DRIVER') ?: 'log'));

        switch ($driver) {
            case 'resend':
                $apiKey = (string)getenv('RESEND_API_KEY');
                $from = (string)(getenv('MAIL_FROM') ?: 'no-reply@audimage.app');
                $fromName = (string)(getenv('MAIL_FROM_NAME') ?: 'AUDIMAGE');
                if ($apiKey === '') {
                    error_log('MailerFactory: MAIL_DRIVER=resend but RESEND_API_KEY is empty — falling back to LogMailer.');
                    return new LogMailer();
                }
                return new ResendMailer($apiKey, $from, $fromName);

            case 'smtp':
                $host = (string)getenv('SMTP_HOST');
                $port = (int)(getenv('SMTP_PORT') ?: 587);
                $user = (string)getenv('SMTP_USER');
                $pass = (string)getenv('SMTP_PASS');
                $from = (string)(getenv('MAIL_FROM') ?: 'no-reply@audimage.app');
                $fromName = (string)(getenv('MAIL_FROM_NAME') ?: 'AUDIMAGE');
                if ($host === '') {
                    error_log('MailerFactory: MAIL_DRIVER=smtp but SMTP_HOST is empty — falling back to LogMailer.');
                    return new LogMailer();
                }
                return new SmtpMailer($host, $port, $user, $pass, $from, $fromName);

            case 'log':
            default:
                return new LogMailer();
        }
    }
}

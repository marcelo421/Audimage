<?php

declare(strict_types=1);

namespace App\Mail;

interface MailerInterface
{
    /**
     * Returns true on (attempted) successful send. Implementations should
     * not throw on transient failures — callers treat email delivery as
     * best-effort and must never let a mail failure block registration
     * (the user can always request a resend).
     */
    public function send(string $to, string $subject, string $htmlBody): bool;
}

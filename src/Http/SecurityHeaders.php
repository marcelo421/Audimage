<?php

declare(strict_types=1);

namespace App\Http;

class SecurityHeaders
{
    public static function apply(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // Permissive enough for the Google Identity script + fonts this
        // project already loads; tighten further if those dependencies change.
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://accounts.google.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; connect-src 'self' https://accounts.google.com; frame-src https://accounts.google.com");
    }
}

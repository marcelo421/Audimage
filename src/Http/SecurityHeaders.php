<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Applies a baseline set of HTTP security headers. Call this once, early,
 * on every request (from bootstrap.php) — before any output is sent.
 */
class SecurityHeaders
{
    public static function apply(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // Mic is used for the visualizer; camera/geolocation are not needed anywhere.
        header('Permissions-Policy: microphone=(self), camera=(), geolocation=()');
        header(
            'Content-Security-Policy: ' .
            "default-src 'self'; " .
            "script-src 'self' https://accounts.google.com; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
            "font-src 'self' https://fonts.gstatic.com; " .
            "img-src 'self' data:; " .
            "connect-src 'self' https://accounts.google.com; " .
            "frame-src https://accounts.google.com; " .
            "frame-ancestors 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'"
        );
    }
}

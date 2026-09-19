<?php

declare(strict_types=1);

namespace App\Http;

// Adiciona cabeçalhos HTTP básicos de segurança.
// Esses headers ajudam a evitar ataques comuns, como clickjacking, MIME sniffing e execução de scripts não esperada.
class SecurityHeaders
{
    // Aplicação dos headers no início de cada requisição, antes de qualquer saída.
    public static function apply(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // Microfone é usado pelo visualizador; câmera e geolocalização não são necessários aqui.
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

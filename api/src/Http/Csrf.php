<?php

declare(strict_types=1);

namespace App\Http;

class Csrf
{
    public static function ensureToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function getToken(): ?string
    {
        return $_SESSION['csrf_token'] ?? null;
    }

    // Validate token from the `X-CSRF-Token` request header.
    public static function validateRequest(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}

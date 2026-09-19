<?php

declare(strict_types=1);

namespace App\Http;

// Classe responsável por proteger formulários e requisições que mudam estado.
// O objetivo é garantir que a requisição veio do próprio app e não de um site externo.
class Csrf
{
    // Gera um token CSRF válido para a sessão atual, se ele ainda não existir.
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

    // Lê o token já guardado na sessão.
    public static function getToken(): ?string
    {
        return $_SESSION['csrf_token'] ?? null;
    }

    // Valida o token enviado pelo navegador no cabeçalho X-CSRF-Token.
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

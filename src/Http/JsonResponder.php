<?php

declare(strict_types=1);

namespace App\Http;

class JsonResponder
{
    public static function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function invalidJson(): void
    {
        self::respond(['ok' => false, 'message' => 'JSON inválido.'], 400);
    }

    public static function serverError(string $message = 'Erro no servidor.'): void
    {
        self::respond(['ok' => false, 'message' => $message], 500);
    }
}

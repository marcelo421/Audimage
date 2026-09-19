<?php

declare(strict_types=1);

namespace App\Http;

// Centraliza a resposta HTTP em JSON.
// Em vez de cada endpoint montar manualmente o cabeçalho e o payload, ele usa esta classe.
class JsonResponder
{
    // Envia uma resposta JSON com status HTTP específico.
    public static function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Resposta padrão quando o corpo da requisição não é um JSON válido.
    public static function invalidJson(): void
    {
        self::respond(['ok' => false, 'message' => 'JSON inválido.'], 400);
    }

    // Resposta genérica para falhas internas do servidor.
    public static function serverError(string $message = 'Erro no servidor.'): void
    {
        self::respond(['ok' => false, 'message' => $message], 500);
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

// Pequena abstração para ler o corpo JSON das requisições HTTP.
// Isso mantém a lógica de parsing centralizada e evita repetir código nos endpoints.
class Request
{
    // Lê o conteúdo bruto da requisição e converte em array associativo.
    public static function getJsonBody(): array
    {
        $payload = file_get_contents('php://input');
        if ($payload === false || trim($payload) === '') {
            return [];
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            JsonResponder::invalidJson();
        }

        return $data;
    }
}

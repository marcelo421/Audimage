<?php

declare(strict_types=1);

namespace App\Http;

class Request
{
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

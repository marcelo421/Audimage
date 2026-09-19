<?php

declare(strict_types=1);

namespace App\Exception;

// Exceção para dados de entrada inválidos, como senha curta ou email mal formatado.
class ValidationException extends AuthException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 400);
    }
}

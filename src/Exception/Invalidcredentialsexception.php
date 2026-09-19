<?php

declare(strict_types=1);

namespace App\Exception;

// Exceção usada quando o usuário digita credenciais erradas ou a conta não existe.
class InvalidCredentialsException extends AuthException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 401);
    }
}

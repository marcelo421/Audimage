<?php

declare(strict_types=1);

namespace App\Exception;

// Exceção usada quando o usuário excede o limite de tentativas por tempo.
class TooManyRequestsException extends DomainException
{
    public function httpStatus(): int
    {
        return 429;
    }
}

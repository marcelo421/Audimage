<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidCredentialsException extends DomainException
{
    public function httpStatus(): int
    {
        return 401;
    }
}

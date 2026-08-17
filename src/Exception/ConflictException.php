<?php

declare(strict_types=1);

namespace App\Exception;

class ConflictException extends DomainException
{
    public function httpStatus(): int
    {
        return 409;
    }
}

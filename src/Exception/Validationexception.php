<?php

declare(strict_types=1);

namespace App\Exception;

class ValidationException extends DomainException
{
    public function httpStatus(): int
    {
        return 400;
    }
}

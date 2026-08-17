<?php

declare(strict_types=1);

namespace App\Exception;

class TooManyRequestsException extends DomainException
{
    public function httpStatus(): int
    {
        return 429;
    }
}

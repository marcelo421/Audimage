<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

abstract class AuthException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus)
    {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

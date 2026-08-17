<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Base class for all domain-level exceptions thrown by Services.
 * Controllers catch these and translate them into HTTP responses —
 * Services themselves never know about HTTP status codes or JSON output.
 */
abstract class DomainException extends RuntimeException
{
    abstract public function httpStatus(): int;
}

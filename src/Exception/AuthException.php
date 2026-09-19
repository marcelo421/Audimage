<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

// Base para qualquer erro de autenticação ou autorização do sistema.
// Cada exceção desta família carrega também um status HTTP apropriado.
abstract class AuthException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus)
    {
        parent::__construct($message);
    }

    // Retorna o código HTTP que a API deve devolver ao cliente.
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

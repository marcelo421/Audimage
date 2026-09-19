<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

// Classe base para erros de negócio do domínio.
// A camada de serviço lança estas exceções; a camada HTTP converte isso em resposta JSON.
abstract class DomainException extends RuntimeException
{
    abstract public function httpStatus(): int;
}

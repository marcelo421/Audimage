<?php

declare(strict_types=1);

namespace App\Exception;

class PaymentRequiredException extends AuthException
{
    public function __construct(string $message = 'Assine o plano mensal para acessar o AUDIMAGE.')
    {
        parent::__construct($message, 402);
    }
}
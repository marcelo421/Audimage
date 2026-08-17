<?php

declare(strict_types=1);

namespace App\Domain;

final class AuthResult
{
    /**
     * @param array{id:int,username:string,email:string} $user
     */
    public function __construct(
        public readonly array $user,
    ) {
    }

    public function toArray(): array
    {
        return ['ok' => true, 'user' => $this->user];
    }
}

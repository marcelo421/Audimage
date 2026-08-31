<?php

declare(strict_types=1);

namespace App\Services;

final class AuthResult
{
    /**
     * @param array{id:int,username:string,email:string} $user
     */
    public function __construct(private array $user)
    {
    }

    /** @return array{ok:true,user:array{id:int,username:string,email:string}} */
    public function toArray(): array
    {
        return ['ok' => true, 'user' => $this->user];
    }

    public function user(): array
    {
        return $this->user;
    }
}

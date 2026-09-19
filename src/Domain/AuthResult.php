<?php

declare(strict_types=1);

namespace App\Domain;

// Valor de retorno padrão usado após um login ou cadastro bem-sucedido.
// Ele encapsula o usuário autenticado em um objeto simples para manter a lógica mais limpa.
final class AuthResult
{
    /**
     * @param array{id:int,username:string,email:string} $user
     */
    public function __construct(private array $user)
    {
    }

    // Converte o resultado em array pronto para responder em JSON.
    /** @return array{ok:true,user:array{id:int,username:string,email:string}} */
    public function toArray(): array
    {
        return ['ok' => true, 'user' => $this->user];
    }

    // Retorna o usuário armazenado no resultado.
    public function user(): array
    {
        return $this->user;
    }
}

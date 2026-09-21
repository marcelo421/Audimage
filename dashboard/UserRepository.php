<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class UserRepository implements UserPasswordResetLookupInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Busca um usuário pelo nome de usuário ou email.
     * É usado no login para verificar se a conta existe e se a senha bate.
     */
    public function findByUsernameOrEmail(string $value): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email, password_hash, email_verified_at, subscription_status FROM users WHERE username = :value1 OR email = :value2 LIMIT 1');
        $stmt->execute([':value1' => $value, ':value2' => $value]);
        return $stmt->fetch();
    }

    /**
     * Busca um usuário pelo email.
     * Usado em fluxos de confirmação, reinício de senha e login com Google.
     */
    public function findByEmail(string $email): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email, email_verified_at, subscription_status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        return $stmt->fetch();
    }

    /**
     * Busca um usuário pelo ID.
     * É útil para carregar dados do usuário autenticado na sessão.
     */
    public function findById(int $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email, subscription_status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Verifica se o nome de usuário já existe.
     * Usado para impedir duplicidade na criação da conta.
     */
    public function findByUsername(string $username): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        return $stmt->fetch();
    }

    /**
     * Verifica se o nome de usuário ou email já existem no banco.
     * Isso é um check de conflito antes de criar um novo cadastro.
     */
    public function existsByUsernameOrEmail(string $username, string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username OR email = :email');
        $stmt->execute([':username' => $username, ':email' => $email]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Cria usuário novo com hash da senha.
     * O password_hash é salvo no banco, nunca a senha em texto puro.
     */
    public function createUser(string $username, string $email, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)');
        $stmt->execute([':username' => $username, ':email' => $email, ':password_hash' => $passwordHash]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Marca o email como confirmado.
     * Isso acontece depois que o link de verificação do email é usado.
     */
    public function markEmailVerified(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    /**
     * Atualiza a senha do usuário para um novo hash.
     * Usado quando o usuário troca a senha ou reseta a senha.
     */
    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $stmt->execute([':password_hash' => $passwordHash, ':id' => $userId]);
    }

    /**
     * Atualiza o status da assinatura de um usuário pelo email.
     * Isso é usado pelo webhook do Stripe para sincronizar o plano do cliente.
     */
    public function updateSubscriptionByEmail(string $email, string $status, ?string $customerId = null, ?string $subscriptionId = null, ?string $periodEnd = null): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET subscription_status = :status, stripe_customer_id = COALESCE(:customer_id, stripe_customer_id), stripe_subscription_id = COALESCE(:subscription_id, stripe_subscription_id), subscription_current_period_end = :period_end WHERE email = :email'
        );
        $stmt->execute([
            ':status' => $status,
            ':customer_id' => $customerId,
            ':subscription_id' => $subscriptionId,
            ':period_end' => $periodEnd,
            ':email' => $email,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Atualiza o status da assinatura usando o ID do cliente do Stripe.
     * Esse caminho ajuda quando o webhook traz o customer ID em vez do email.
     */
    public function updateSubscriptionByCustomerId(string $customerId, string $status, ?string $subscriptionId = null, ?string $periodEnd = null): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET subscription_status = :status, stripe_subscription_id = COALESCE(:subscription_id, stripe_subscription_id), subscription_current_period_end = :period_end WHERE stripe_customer_id = :customer_id'
        );
        $stmt->execute([
            ':status' => $status,
            ':subscription_id' => $subscriptionId,
            ':period_end' => $periodEnd,
            ':customer_id' => $customerId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Lista todos os usuários para o painel administrativo.
     * Usado exclusivamente pelo dashboard do adm_audimage.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, username, email, email_verified_at, subscription_status, created_at
             FROM users ORDER BY created_at DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Atualiza o status de assinatura/permissão de um usuário pelo ID.
     * Usado pelo admin para liberar ou bloquear acesso manualmente.
     */
    public function updateSubscriptionStatusById(int $userId, string $status): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET subscription_status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $userId]);
        return $stmt->rowCount() > 0;
    }
}

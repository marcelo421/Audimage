<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class UserRepository implements UserPasswordResetLookupInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByUsernameOrEmail(string $value): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email, password_hash, email_verified_at, subscription_status FROM users WHERE username = :value1 OR email = :value2 LIMIT 1');
        $stmt->execute([':value1' => $value, ':value2' => $value]);
        return $stmt->fetch();
    }

    public function findByEmail(string $email): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email, email_verified_at, subscription_status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        return $stmt->fetch();
    }

    public function findById(int $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email, subscription_status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function findByUsername(string $username): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        return $stmt->fetch();
    }

    public function existsByUsernameOrEmail(string $username, string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username OR email = :email');
        $stmt->execute([':username' => $username, ':email' => $email]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function createUser(string $username, string $email, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)');
        $stmt->execute([':username' => $username, ':email' => $email, ':password_hash' => $passwordHash]);
        return (int)$this->pdo->lastInsertId();
    }

    public function markEmailVerified(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $stmt->execute([':password_hash' => $passwordHash, ':id' => $userId]);
    }

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
}

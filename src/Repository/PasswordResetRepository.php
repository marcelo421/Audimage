<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class PasswordResetRepository implements PasswordResetRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, expires_at, used_at FROM password_resets WHERE token_hash = :token_hash LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function markUsed(string $tokenHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE token_hash = :token_hash'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
    }

    public function invalidateAllForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL'
        );
        $stmt->execute([':user_id' => $userId]);
    }
}

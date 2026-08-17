<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByUsernameOrEmail(string $value): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE username = :value OR email = :value LIMIT 1');
        $stmt->execute([':value' => $value]);
        return $stmt->fetch();
    }

    public function findByEmail(string $email): array|false
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
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
}

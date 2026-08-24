<?php

declare(strict_types=1);

namespace App\Repository;

interface PasswordResetRepositoryInterface
{
    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void;

    /** Returns ['user_id' => int, 'expires_at' => string, 'used_at' => ?string] or null if not found. */
    public function findByTokenHash(string $tokenHash): ?array;

    public function markUsed(string $tokenHash): void;

    /** Invalidates any previously issued, still-valid tokens for this user before issuing a new one. */
    public function invalidateAllForUser(int $userId): void;
}

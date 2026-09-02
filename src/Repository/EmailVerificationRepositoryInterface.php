<?php

declare(strict_types=1);

namespace App\Repository;

interface EmailVerificationRepositoryInterface
{
    /** Stores a new token hash for the user and returns its expiry timestamp handling is caller's responsibility. */
    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void;

    /** Returns ['user_id' => int, 'expires_at' => string, 'used_at' => ?string] or null if not found. */
    public function findByTokenHash(string $tokenHash): ?array;

    public function markUsed(string $tokenHash): void;

    /** Invalidates (marks used) any previously issued, still-valid tokens for this user — called before issuing a new one on resend. */
    public function invalidateAllForUser(int $userId): void;
}

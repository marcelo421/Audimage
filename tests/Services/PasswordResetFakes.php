<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailVerificationFakes.php';

use App\Repository\PasswordResetRepositoryInterface;
use App\Repository\UserPasswordResetLookupInterface;

final class InMemoryPasswordResetRepository implements PasswordResetRepositoryInterface
{
    /** @var array<string, array{user_id:int, expires_at:string, used_at:?string}> */
    public array $tokens = [];

    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->tokens[$tokenHash] = [
            'user_id' => $userId,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'used_at' => null,
        ];
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        return $this->tokens[$tokenHash] ?? null;
    }

    public function markUsed(string $tokenHash): void
    {
        if (isset($this->tokens[$tokenHash])) {
            $this->tokens[$tokenHash]['used_at'] = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        }
    }

    public function invalidateAllForUser(int $userId): void
    {
        foreach ($this->tokens as $hash => $record) {
            if ($record['user_id'] === $userId && $record['used_at'] === null) {
                $this->tokens[$hash]['used_at'] = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            }
        }
    }
}

final class InMemoryUserRepositoryWithPassword extends InMemoryUserRepository implements UserPasswordResetLookupInterface
{
    /** @var array<int, string> */
    public array $passwordHashes = [];

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $this->passwordHashes[$userId] = $passwordHash;
    }
}

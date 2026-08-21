<?php
declare(strict_types=1);

use App\Repository\EmailVerificationRepositoryInterface;
use App\Repository\UserEmailVerificationLookupInterface;
use App\Mail\MailerInterface;

final class InMemoryEmailVerificationRepository implements EmailVerificationRepositoryInterface
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

final class InMemoryUserRepository implements UserEmailVerificationLookupInterface
{
    /** @var array<int, array{id:int, username:string, email:string, email_verified_at:?string}> */
    public array $usersById = [];

    public function addUser(int $id, string $username, string $email, ?string $verifiedAt = null): void
    {
        $this->usersById[$id] = ['id' => $id, 'username' => $username, 'email' => $email, 'email_verified_at' => $verifiedAt];
    }

    public function findByEmail(string $email): array|false
    {
        foreach ($this->usersById as $user) {
            if ($user['email'] === $email) {
                return $user;
            }
        }
        return false;
    }

    public function markEmailVerified(int $userId): void
    {
        if (isset($this->usersById[$userId])) {
            $this->usersById[$userId]['email_verified_at'] = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        }
    }
}

final class SpyMailer implements MailerInterface
{
    /** @var array<int, array{to:string, subject:string, body:string}> */
    public array $sent = [];
    public bool $shouldSucceed = true;

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $htmlBody];
        return $this->shouldSucceed;
    }
}

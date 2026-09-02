<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Extends the email-verification lookup interface with the one extra
 * operation PasswordResetService needs. UserRepository implements this
 * (which also satisfies UserEmailVerificationLookupInterface), so both
 * services stay decoupled from a live PDO connection for testing.
 */
interface UserPasswordResetLookupInterface extends UserEmailVerificationLookupInterface
{
    public function updatePasswordHash(int $userId, string $passwordHash): void;
}

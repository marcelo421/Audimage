<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Narrow interface covering only what EmailVerificationService needs from
 * the user repository. UserRepository implements this alongside its full
 * concrete API — this exists purely so EmailVerificationService (and its
 * tests) don't need to depend on a live PDO connection.
 */
interface UserEmailVerificationLookupInterface
{
    public function findByEmail(string $email): array|false;

    public function markEmailVerified(int $userId): void;
}

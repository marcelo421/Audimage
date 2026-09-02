<?php
declare(strict_types=1);

require_once __DIR__ . '/PasswordResetFakes.php';

use PHPUnit\Framework\TestCase;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;
use App\Exception\TooManyRequestsException;

final class PasswordResetServiceTest extends TestCase
{
    private InMemoryUserRepositoryWithPassword $users;
    private InMemoryPasswordResetRepository $resets;
    private SpyMailer $mailer;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepositoryWithPassword();
        $this->resets = new InMemoryPasswordResetRepository();
        $this->mailer = new SpyMailer();

        putenv('REDIS_HOST=127.0.0.1');
        putenv('REDIS_PORT=1');
        putenv('RATE_LIMIT_ALLOW_FILE_FALLBACK');
    }

    protected function tearDown(): void
    {
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');
        putenv('RATE_LIMIT_ALLOW_FILE_FALLBACK');
    }

    private function makeService(int $maxAttempts = 5): PasswordResetService
    {
        $rlFile = sys_get_temp_dir() . '/audimage_test_pwreset_rl_' . uniqid() . '.json';
        return new PasswordResetService(
            $this->users,
            $this->resets,
            $this->mailer,
            new RateLimiter(maxAttempts: $maxAttempts, decaySeconds: 900, fallbackFile: $rlFile),
            'https://audimage.example.com'
        );
    }

    private function extractToken(string $body): string
    {
        preg_match('/reset_token=([a-f0-9]+)/', $body, $m);
        return $m[1] ?? '';
    }

    public function testRequestResetForNonexistentEmailSendsNothing(): void
    {
        $service = $this->makeService();
        $service->requestReset('nobody@example.com', '203.0.113.1');

        $this->assertCount(0, $this->mailer->sent);
    }

    public function testRequestResetSendsEmailWithHashedToken(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();
        $service->requestReset('user@example.com', '203.0.113.1');

        $this->assertCount(1, $this->mailer->sent);
        $rawToken = $this->extractToken($this->mailer->sent[0]['body']);
        $this->assertNotSame('', $rawToken);
        $this->assertArrayNotHasKey($rawToken, $this->resets->tokens);
        $this->assertArrayHasKey(hash('sha256', $rawToken), $this->resets->tokens);
    }

    public function testResetPasswordWithValidTokenUpdatesHashAndVerifiesEmail(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com'); // starts unverified
        $service = $this->makeService();
        $service->requestReset('user@example.com', '203.0.113.1');
        $rawToken = $this->extractToken($this->mailer->sent[0]['body']);

        $result = $service->resetPassword($rawToken, 'NovaSenha123');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey(1, $this->users->passwordHashes);
        $this->assertTrue(password_verify('NovaSenha123', $this->users->passwordHashes[1]));
        $this->assertNotNull($this->users->usersById[1]['email_verified_at'], 'successful reset should also mark email verified');
    }

    public function testResetPasswordTokenIsSingleUse(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();
        $service->requestReset('user@example.com', '203.0.113.1');
        $rawToken = $this->extractToken($this->mailer->sent[0]['body']);

        $first = $service->resetPassword($rawToken, 'NovaSenha123');
        $second = $service->resetPassword($rawToken, 'OutraSenha456');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
    }

    public function testResetPasswordWithExpiredTokenFails(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $this->resets->create(1, hash('sha256', 'expired-raw'), new \DateTimeImmutable('-1 minute'));
        $service = $this->makeService();

        $result = $service->resetPassword('expired-raw', 'NovaSenha123');

        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey(1, $this->users->passwordHashes);
    }

    public function testResetPasswordWithWrongTokenFails(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();
        $service->requestReset('user@example.com', '203.0.113.1');

        $result = $service->resetPassword(bin2hex(random_bytes(32)), 'NovaSenha123');

        $this->assertFalse($result['ok']);
    }

    public function testResetPasswordRejectsWeakPassword(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();
        $service->requestReset('user@example.com', '203.0.113.1');
        $rawToken = $this->extractToken($this->mailer->sent[0]['body']);

        $result = $service->resetPassword($rawToken, 'short');

        $this->assertFalse($result['ok']);
        // Token must still be valid/unused after a rejected weak password —
        // the user should be able to retry with a stronger one.
        $this->assertArrayHasKey(hash('sha256', $rawToken), $this->resets->tokens);
        $this->assertNull($this->resets->tokens[hash('sha256', $rawToken)]['used_at']);
    }

    public function testRequestResetInvalidatesPreviousToken(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();

        $service->requestReset('user@example.com', '203.0.113.1');
        $firstToken = $this->extractToken($this->mailer->sent[0]['body']);

        $service->requestReset('user@example.com', '203.0.113.1');

        $result = $service->resetPassword($firstToken, 'NovaSenha123');
        $this->assertFalse($result['ok'], 'earlier token must be invalidated by a newer request');
    }

    public function testRequestResetIsRateLimitedPerEmail(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService(3);

        $this->expectException(TooManyRequestsException::class);
        for ($i = 0; $i < 10; $i++) {
            $service->requestReset('user@example.com', '203.0.113.' . $i);
        }
    }

    public function testRequestResetIsRateLimitedPerIpAcrossDifferentEmails(): void
    {
        $this->users->addUser(1, 'user1', 'user1@example.com');
        $this->users->addUser(2, 'user2', 'user2@example.com');
        $service = $this->makeService(3);

        $this->expectException(TooManyRequestsException::class);
        for ($i = 0; $i < 10; $i++) {
            $email = $i % 2 === 0 ? 'user1@example.com' : 'user2@example.com';
            $service->requestReset($email, '203.0.113.99'); // same IP every time
        }
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailVerificationFakes.php';

use PHPUnit\Framework\TestCase;
use App\Services\EmailVerificationService;
use App\Services\RateLimiter;
use App\Exception\TooManyRequestsException;

final class EmailVerificationServiceTest extends TestCase
{
    private InMemoryUserRepository $users;
    private InMemoryEmailVerificationRepository $verifications;
    private SpyMailer $mailer;
    private string $rateLimiterFile;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->verifications = new InMemoryEmailVerificationRepository();
        $this->mailer = new SpyMailer();
        $this->rateLimiterFile = sys_get_temp_dir() . '/audimage_test_ev_rl_' . uniqid() . '.json';

        // Force RateLimiter through its deterministic file-based fallback
        // (no real Redis available in this environment).
        putenv('REDIS_HOST=127.0.0.1');
        putenv('REDIS_PORT=1');
        putenv('RATE_LIMIT_ALLOW_FILE_FALLBACK'); // default: allowed
    }

    protected function tearDown(): void
    {
        @unlink($this->rateLimiterFile);
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');
        putenv('RATE_LIMIT_ALLOW_FILE_FALLBACK');
    }

    private function makeService(): EmailVerificationService
    {
        return new EmailVerificationService(
            $this->users,
            $this->verifications,
            $this->mailer,
            new RateLimiter(maxAttempts: 5, decaySeconds: 900, fallbackFile: $this->rateLimiterFile),
            'https://audimage.example.com'
        );
    }

    public function testSendVerificationEmailStoresHashNotRawToken(): void
    {
        $service = $this->makeService();
        $service->sendVerificationEmail(1, 'user@example.com', 'user1');

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('user@example.com', $this->mailer->sent[0]['to']);

        // Extract the raw token from the email body/link.
        preg_match('/token=([a-f0-9]+)/', $this->mailer->sent[0]['body'], $matches);
        $rawToken = $matches[1] ?? '';
        $this->assertNotSame('', $rawToken);

        // The stored record must be keyed by the SHA-256 hash, not the raw token.
        $this->assertArrayNotHasKey($rawToken, $this->verifications->tokens);
        $this->assertArrayHasKey(hash('sha256', $rawToken), $this->verifications->tokens);
    }

    public function testVerifyWithValidTokenMarksEmailVerified(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();
        $service->sendVerificationEmail(1, 'user@example.com', 'user1');

        preg_match('/token=([a-f0-9]+)/', $this->mailer->sent[0]['body'], $matches);
        $rawToken = $matches[1];

        $result = $service->verify($rawToken);

        $this->assertTrue($result);
        $this->assertNotNull($this->users->usersById[1]['email_verified_at']);
    }

    public function testVerifyWithWrongTokenFails(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();
        $service->sendVerificationEmail(1, 'user@example.com', 'user1');

        $result = $service->verify(bin2hex(random_bytes(32)));

        $this->assertFalse($result);
        $this->assertNull($this->users->usersById[1]['email_verified_at']);
    }

    public function testVerifyTokenIsSingleUse(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();
        $service->sendVerificationEmail(1, 'user@example.com', 'user1');

        preg_match('/token=([a-f0-9]+)/', $this->mailer->sent[0]['body'], $matches);
        $rawToken = $matches[1];

        $this->assertTrue($service->verify($rawToken), 'first use should succeed');
        $this->assertFalse($service->verify($rawToken), 'second use of the same token must fail');
    }

    public function testVerifyExpiredTokenFails(): void
    {
        // Manually seed an already-expired token, bypassing sendVerificationEmail's TTL.
        $this->users->addUser(1, 'user1', 'user@example.com');
        $this->verifications->create(1, hash('sha256', 'raw-expired-token'), new \DateTimeImmutable('-1 hour'));

        $service = $this->makeService();
        $result = $service->verify('raw-expired-token');

        $this->assertFalse($result);
        $this->assertNull($this->users->usersById[1]['email_verified_at']);
    }

    public function testResendDoesNotRevealWhetherEmailExists(): void
    {
        $service = $this->makeService();

        // No exception, no distinguishable outcome for a non-existent email.
        $service->resend('nobody@example.com', '203.0.113.5');

        $this->assertCount(0, $this->mailer->sent, 'no email should be sent for a non-existent account');
    }

    public function testResendSkipsAlreadyVerifiedAccounts(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com', '2026-01-01 00:00:00');
        $service = $this->makeService();

        $service->resend('user@example.com', '203.0.113.5');

        $this->assertCount(0, $this->mailer->sent, 'already-verified accounts should not receive another verification email');
    }

    public function testResendInvalidatesPreviousTokenBeforeIssuingNewOne(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();

        $service->sendVerificationEmail(1, 'user@example.com', 'user1');
        preg_match('/token=([a-f0-9]+)/', $this->mailer->sent[0]['body'], $matches);
        $firstToken = $matches[1];

        $service->resend('user@example.com', '203.0.113.5');

        // The old token must no longer verify — only the newest one should work.
        $this->assertFalse($service->verify($firstToken));
    }

    public function testResendIsRateLimitedPerEmail(): void
    {
        $this->users->addUser(1, 'user1', 'user@example.com');
        $service = $this->makeService();

        $this->expectException(TooManyRequestsException::class);
        for ($i = 0; $i < 10; $i++) {
            $service->resend('user@example.com', '203.0.113.' . $i); // vary IP, keep email constant
        }
    }
}

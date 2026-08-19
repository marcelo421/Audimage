<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\RateLimiter;
use App\Exception\TooManyRequestsException;

final class RateLimiterTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/audimage_test_rate_limits_' . uniqid() . '.json';
        putenv('RATE_LIMITER_FALLBACK'); // reset to default ("file")
        // Point at an unreachable host so Redis never connects in tests,
        // forcing every test through the fallback path deterministically.
        putenv('REDIS_HOST=127.0.0.1');
        putenv('REDIS_PORT=1'); // reserved/closed port -> connection refused
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
        putenv('RATE_LIMITER_FALLBACK');
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');
    }

    public function testFileFallbackAllowsUpToMaxAttempts(): void
    {
        $limiter = new RateLimiter(maxAttempts: 3, decaySeconds: 900, fallbackFile: $this->tmpFile);

        $limiter->enforce('login', 'user@example.com');
        $limiter->enforce('login', 'user@example.com');
        $limiter->enforce('login', 'user@example.com');

        $this->assertTrue(true, 'first three attempts should not throw');
    }

    public function testFileFallbackBlocksAfterMaxAttempts(): void
    {
        $limiter = new RateLimiter(maxAttempts: 2, decaySeconds: 900, fallbackFile: $this->tmpFile);

        $limiter->enforce('login', 'user@example.com');
        $limiter->enforce('login', 'user@example.com');

        $this->expectException(TooManyRequestsException::class);
        $limiter->enforce('login', 'user@example.com');
    }

    public function testFileFallbackTracksIdentifiersIndependently(): void
    {
        $limiter = new RateLimiter(maxAttempts: 1, decaySeconds: 900, fallbackFile: $this->tmpFile);

        $limiter->enforce('login', 'alice@example.com');

        // A different identifier must not be affected by alice's attempts.
        $limiter->enforce('login', 'bob@example.com');

        $this->expectException(TooManyRequestsException::class);
        $limiter->enforce('login', 'alice@example.com');
    }

    public function testClosedModeRejectsImmediatelyWithoutRedis(): void
    {
        putenv('RATE_LIMITER_FALLBACK=closed');
        $limiter = new RateLimiter(maxAttempts: 100, decaySeconds: 900, fallbackFile: $this->tmpFile);

        // Fail-closed: even the FIRST attempt is rejected while the primary
        // store is unavailable, rather than silently letting it through.
        $this->expectException(TooManyRequestsException::class);
        $limiter->enforce('login', 'user@example.com');
    }
}

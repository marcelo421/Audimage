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
        putenv('RATE_LIMIT_ALLOW_FILE_FALLBACK'); // reset to default (unset = "1", file allowed)
        // Point at an unreachable host so Redis never connects in tests,
        // forcing every test through the fallback path deterministically.
        putenv('REDIS_HOST=127.0.0.1');
        putenv('REDIS_PORT=1'); // reserved/closed port -> connection refused
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
        putenv('RATE_LIMIT_ALLOW_FILE_FALLBACK');
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

    public function testFileFallbackDisabledRejectsImmediatelyWithoutRedis(): void
    {
        putenv('RATE_LIMIT_ALLOW_FILE_FALLBACK=0');
        $limiter = new RateLimiter(maxAttempts: 100, decaySeconds: 900, fallbackFile: $this->tmpFile);

        // Fail-closed: even the FIRST attempt is rejected while the primary
        // store is unavailable, rather than silently letting it through.
        // This is the required production setting (RATE_LIMIT_ALLOW_FILE_FALLBACK=0).
        $this->expectException(TooManyRequestsException::class);
        $limiter->enforce('login', 'user@example.com');
    }

    public function testIsBackedByRedisReflectsConnectivity(): void
    {
        // Unreachable host/port configured in setUp() -> Redis never connects.
        $limiter = new RateLimiter(maxAttempts: 5, decaySeconds: 900, fallbackFile: $this->tmpFile);
        $this->assertFalse($limiter->isBackedByRedis());
    }
}

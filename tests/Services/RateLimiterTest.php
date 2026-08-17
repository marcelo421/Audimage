<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\RateLimiter;

final class RateLimiterTest extends TestCase
{
    public function testFailsClosedWhenNoBackendAvailable(): void
    {
        // No Redis in the test environment and file fallback explicitly
        // disabled: enforce() must throw rather than silently allow.
        $limiter = new RateLimiter(allowFileFallback: false);

        $this->expectException(\RuntimeException::class);
        $limiter->enforce('login', 'someone');
    }

    public function testFileFallbackEnforcesLimitWhenExplicitlyEnabled(): void
    {
        $tmpFile = sys_get_temp_dir() . '/audimage_test_rate_limits_' . uniqid() . '.json';
        $limiter = new RateLimiter(
            maxAttempts: 2,
            decaySeconds: 900,
            allowFileFallback: true,
            fallbackFile: $tmpFile,
        );

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        // First two attempts pass silently.
        $limiter->enforce('login', 'user-a');
        $limiter->enforce('login', 'user-a');

        // Third attempt within the window exceeds maxAttempts.
        $this->expectException(\App\Exception\TooManyRequestsException::class);
        try {
            $limiter->enforce('login', 'user-a');
        } finally {
            @unlink($tmpFile);
        }
    }
}

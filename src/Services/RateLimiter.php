<?php

declare(strict_types=1);

namespace App\Services;

use App\Exception\TooManyRequestsException;
use Redis;

class RateLimiter
{
    private ?Redis $redis = null;
    private int $maxAttempts;
    private int $decaySeconds;

    public function __construct(int $maxAttempts = 8, int $decaySeconds = 900)
    {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;

        if (class_exists(Redis::class)) {
            $redis = new Redis();
            if (@$redis->connect('127.0.0.1', 6379) === true) {
                $this->redis = $redis;
            }
        }
    }

    public function enforce(string $scope, string $identifier): void
    {
        if ($this->redis === null) {
            error_log('RateLimiter: Redis indisponível, continuando sem limitação.');
            return;
        }

        $key = sprintf('rate_limit:%s:%s', preg_replace('/[^a-zA-Z0-9_:-]/', '_', $scope), preg_replace('/[^a-zA-Z0-9_:-]/', '_', $identifier));
        $attempts = $this->redis->incr($key);

        if ($attempts === 1) {
            $this->redis->expire($key, $this->decaySeconds);
        }

        if ($attempts > $this->maxAttempts) {
            throw new TooManyRequestsException('Muitas tentativas. Tente novamente mais tarde.');
        }
    }
}

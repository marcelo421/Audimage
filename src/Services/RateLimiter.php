<?php

declare(strict_types=1);

namespace App\Services;

use App\Exception\TooManyRequestsException;
use Redis;
use RedisException;

/**
 * Rate limiter that fails CLOSED, not open.
 *
 * If Redis is reachable, it is used (fast, shared across processes/hosts).
 * If Redis is unavailable, we fall back to a file-based limiter instead of
 * disabling rate limiting entirely — an attacker should never be able to
 * bypass throttling just by causing Redis to be unreachable.
 */
class RateLimiter
{
    private ?Redis $redis = null;
    private int $maxAttempts;
    private int $decaySeconds;
    private string $fallbackFile;
    private string $fallbackMode;

    public function __construct(
        int $maxAttempts = 8,
        int $decaySeconds = 900,
        ?string $fallbackFile = null,
        ?string $redisHost = null,
        ?int $redisPort = null
    ) {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
        $this->fallbackFile = $fallbackFile ?? sys_get_temp_dir() . '/audimage_rate_limits.json';

        // RATE_LIMITER_FALLBACK=closed rejects every request while Redis is
        // down instead of degrading to the file-based limiter. Default is
        // "file" so the app stays usable (with slightly coarser limiting)
        // during a Redis outage rather than going fully unavailable.
        $mode = strtolower((string)(getenv('RATE_LIMITER_FALLBACK') ?: 'file'));
        $this->fallbackMode = $mode === 'closed' ? 'closed' : 'file';

        $host = $redisHost ?? (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = $redisPort ?? (int)(getenv('REDIS_PORT') ?: 6379);

        if (class_exists(Redis::class)) {
            try {
                $redis = new Redis();
                if (@$redis->connect($host, $port, 1.0) === true) {
                    $this->redis = $redis;
                }
            } catch (RedisException $e) {
                $this->redis = null;
                error_log('RateLimiter: Redis connect failed, using file-based fallback: ' . $e->getMessage());
            }
        }
    }

    public function enforce(string $scope, string $identifier): void
    {
        if ($this->redis !== null) {
            try {
                $this->enforceWithRedis($scope, $identifier);
                return;
            } catch (RedisException $e) {
                error_log('RateLimiter: Redis error mid-request, falling back to file limiter: ' . $e->getMessage());
                $this->redis = null;
                // fall through to file-based enforcement below
            }
        }

        if ($this->fallbackMode === 'closed') {
            error_log('RateLimiter: Redis indisponível, RATE_LIMITER_FALLBACK=closed — bloqueando requisição.');
            throw new TooManyRequestsException('Serviço temporariamente indisponível. Tente novamente em instantes.');
        }

        $this->enforceWithFile($scope, $identifier);
    }

    private function enforceWithRedis(string $scope, string $identifier): void
    {
        $key = $this->buildKey($scope, $identifier);
        $attempts = $this->redis->incr($key);

        if ($attempts === 1) {
            $this->redis->expire($key, $this->decaySeconds);
        }

        if ($attempts > $this->maxAttempts) {
            throw new TooManyRequestsException('Muitas tentativas. Tente novamente mais tarde.');
        }
    }

    /**
     * File-based fallback. Uses flock() for concurrency safety and prunes
     * expired windows on every call. Not as fast/scalable as Redis, but it
     * guarantees limiting still applies when Redis is down.
     */
    private function enforceWithFile(string $scope, string $identifier): void
    {
        $key = $this->buildKey($scope, $identifier);
        $now = time();

        $handle = fopen($this->fallbackFile, 'c+');
        if ($handle === false) {
            // We cannot open the fallback store at all — fail closed rather
            // than let the request through unthrottled.
            throw new TooManyRequestsException('Limitador de tentativas indisponível. Tente novamente em instantes.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new TooManyRequestsException('Limitador de tentativas indisponível. Tente novamente em instantes.');
            }

            $size = fstat($handle)['size'] ?? 0;
            $raw = $size > 0 ? fread($handle, $size) : '';
            $data = $raw ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }

            foreach ($data as $entryKey => $entry) {
                if (!is_array($entry) || !isset($entry['window_start']) || ($now - (int)$entry['window_start']) > $this->decaySeconds) {
                    unset($data[$entryKey]);
                }
            }

            if (!isset($data[$key]) || !is_array($data[$key])) {
                $data[$key] = ['count' => 0, 'window_start' => $now];
            }

            if (($now - (int)$data[$key]['window_start']) > $this->decaySeconds) {
                $data[$key] = ['count' => 0, 'window_start' => $now];
            }

            $data[$key]['count'] = (int)$data[$key]['count'] + 1;
            $exceeded = $data[$key]['count'] > $this->maxAttempts;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($handle);

            if ($exceeded) {
                throw new TooManyRequestsException('Muitas tentativas. Tente novamente mais tarde.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function buildKey(string $scope, string $identifier): string
    {
        $safeScope = preg_replace('/[^a-zA-Z0-9_:-]/', '_', $scope);
        $safeIdentifier = preg_replace('/[^a-zA-Z0-9_:-]/', '_', $identifier);
        return sprintf('rate_limit:%s:%s', $safeScope, $safeIdentifier);
    }
}

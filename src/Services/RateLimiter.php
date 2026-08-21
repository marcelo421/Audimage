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
    private bool $allowFileFallback;

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

        // RATE_LIMIT_ALLOW_FILE_FALLBACK controls what happens when Redis is
        // unreachable. Default (unset, or "1") = degrade to a file-based
        // limiter, which is the right call for local dev/staging so the app
        // stays usable without Redis running.
        //
        // In production this MUST be set to "0" explicitly. A per-host file
        // fallback behind a load balancer is not a shared limiter — each
        // instance counts independently, so the *effective* limit an
        // attacker sees is max_attempts × number_of_instances. Fail-closed
        // (reject with 503 instead) is the correct behavior once you have
        // more than one app host, because a silently-weaker rate limiter is
        // worse than a loud, alertable outage.
        $flag = getenv('RATE_LIMIT_ALLOW_FILE_FALLBACK');
        $this->allowFileFallback = $flag === false ? true : $flag !== '0';

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
                error_log('RateLimiter: Redis connect failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Reflects Redis connectivity at construction time. Used by the
     * healthcheck endpoint — since a RateLimiter is constructed fresh per
     * request (see api/dependencies.php), polling this via /api/health.php
     * gives an up-to-date signal without any extra connection overhead.
     */
    public function isBackedByRedis(): bool
    {
        return $this->redis !== null;
    }

    public function enforce(string $scope, string $identifier): void
    {
        if ($this->redis !== null) {
            try {
                $this->enforceWithRedis($scope, $identifier);
                return;
            } catch (RedisException $e) {
                error_log('RateLimiter: Redis error mid-request, Redis marked unavailable for rest of request: ' . $e->getMessage());
                $this->redis = null;
                // fall through to fallback handling below
            }
        }

        if (!$this->allowFileFallback) {
            // Structured marker so log-based alerting (CloudWatch metric
            // filter, Datadog log monitor, etc.) can match on this exact
            // string regardless of future message wording changes.
            error_log('[RATE_LIMITER_FALLBACK_TRIGGERED] mode=closed scope=' . $scope . ' redis_down=1');
            throw new TooManyRequestsException('Serviço temporariamente indisponível. Tente novamente em instantes.');
        }

        error_log('[RATE_LIMITER_FALLBACK_TRIGGERED] mode=file scope=' . $scope . ' redis_down=1');
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

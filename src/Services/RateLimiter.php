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
    private bool $allowFileFallback;
    private string $fallbackFile;

    public function __construct(int $maxAttempts = 8, int $decaySeconds = 900)
    {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
        $this->allowFileFallback = (getenv('RATE_LIMIT_ALLOW_FILE_FALLBACK') ?: 'true') !== 'false';
        $this->fallbackFile = getenv('RATE_LIMIT_FALLBACK_FILE') ?: sys_get_temp_dir() . '/audimage_rate_limits.json';

        if (class_exists(Redis::class)) {
            try {
                $redis = new Redis();
                if (@$redis->connect('127.0.0.1', 6379, 1.0) === true) {
                    $this->redis = $redis;
                }
            } catch (\Throwable $e) {
                $this->redis = null;
            }
        }

        if ($this->redis === null) {
            error_log('[RATE_LIMITER_ALERT] Redis unavailable at startup — ' .
                ($this->allowFileFallback ? 'using file-based fallback (degraded mode).' : 'failing closed, all requests will be denied.'));
        }
    }

    public function isBackedByRedis(): bool
    {
        return $this->redis !== null;
    }

    public function enforce(string $scope, string $identifier): void
    {
        $key = sprintf(
            '%s:%s',
            preg_replace('/[^a-zA-Z0-9_:-]/', '_', $scope),
            preg_replace('/[^a-zA-Z0-9_:-]/', '_', $identifier)
        );

        if ($this->redis !== null) {
            $this->enforceWithRedis($key);
            return;
        }

        if (!$this->allowFileFallback) {
            // Fail-closed: no Redis, no fallback allowed — deny by default.
            error_log('[RATE_LIMITER_ALERT] Denying request: Redis down and file fallback disabled. scope=' . $scope);
            throw new TooManyRequestsException('Serviço temporariamente indisponível. Tente novamente em instantes.');
        }

        $this->enforceWithFile($key);
    }

    private function enforceWithRedis(string $key): void
    {
        $redisKey = 'rate_limit:' . $key;

        try {
            $attempts = $this->redis->incr($redisKey);
            if ($attempts === 1) {
                $this->redis->expire($redisKey, $this->decaySeconds);
            }
        } catch (\Throwable $e) {
            error_log('[RATE_LIMITER_ALERT] Redis failed mid-request, falling back to file mode: ' . $e->getMessage());
            $this->redis = null;
            if (!$this->allowFileFallback) {
                throw new TooManyRequestsException('Serviço temporariamente indisponível. Tente novamente em instantes.');
            }
            $this->enforceWithFile($key);
            return;
        }

        if ($attempts > $this->maxAttempts) {
            throw new TooManyRequestsException('Muitas tentativas. Tente novamente mais tarde.');
        }
    }

    private function enforceWithFile(string $key): void
    {
        $fp = @fopen($this->fallbackFile, 'c+');
        if ($fp === false) {
            // Cannot even use the fallback store — fail closed rather than allow unlimited attempts.
            error_log('[RATE_LIMITER_ALERT] Fallback file unavailable, denying request. key=' . $key);
            throw new TooManyRequestsException('Serviço temporariamente indisponível. Tente novamente em instantes.');
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $now = time();
        foreach ($data as $k => $entry) {
            if (!is_array($entry) || !isset($entry['window_start']) || ($now - (int)$entry['window_start']) > $this->decaySeconds) {
                unset($data[$k]);
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

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($exceeded) {
            throw new TooManyRequestsException('Muitas tentativas. Tente novamente mais tarde.');
        }
    }
}

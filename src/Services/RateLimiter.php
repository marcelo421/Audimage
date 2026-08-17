<?php

declare(strict_types=1);

namespace App\Services;

use App\Exception\TooManyRequestsException;
use Redis;

/**
 * Rate limiter with two backends:
 *  - Redis (preferred, atomic INCR/EXPIRE, safe under concurrency)
 *  - File-based fallback with flock() (used only if explicitly allowed
 *    via RATE_LIMIT_ALLOW_FILE_FALLBACK=1, e.g. local dev without Redis)
 *
 * IMPORTANT: unlike the previous implementation, this class FAILS CLOSED.
 * If Redis is unavailable and the file fallback is not explicitly enabled,
 * enforce() throws instead of silently allowing unlimited attempts. A
 * security control that disappears silently when its dependency is down
 * is worse than no control at all — the caller must be able to trust that
 * "no exception" means "the limit was actually checked".
 */
class RateLimiter
{
    private ?Redis $redis = null;
    private bool $allowFileFallback;
    private string $fallbackFile;

    public function __construct(
        private int $maxAttempts = 8,
        private int $decaySeconds = 900,
        ?bool $allowFileFallback = null,
        ?string $fallbackFile = null,
    ) {
        $this->allowFileFallback = $allowFileFallback ?? (getenv('RATE_LIMIT_ALLOW_FILE_FALLBACK') === '1');
        $this->fallbackFile = $fallbackFile ?? (__DIR__ . '/../../storage/.rate_limits.json');

        if (class_exists(Redis::class)) {
            $redis = new Redis();
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            if (@$redis->connect($host, $port, 1.0) === true) {
                $this->redis = $redis;
            }
        }
    }

    /**
     * @throws TooManyRequestsException if the limit was exceeded
     * @throws \RuntimeException if no backend is available and the
     *         file fallback was not explicitly enabled (fail-closed)
     */
    public function enforce(string $scope, string $identifier): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = hash('sha256', $scope . ':' . $identifier . ':' . $ip);

        if ($this->redis !== null) {
            $this->enforceViaRedis($key);
            return;
        }

        if ($this->allowFileFallback) {
            error_log('RateLimiter: Redis indisponível, usando fallback em arquivo.');
            $this->enforceViaFile($key);
            return;
        }

        // Fail closed: we cannot verify the caller is under the limit,
        // so we refuse to proceed rather than silently allow the request.
        error_log('RateLimiter: Redis indisponível e fallback em arquivo desabilitado. Bloqueando por segurança.');
        throw new \RuntimeException(
            'Serviço de limitação de taxa indisponível. Tente novamente em instantes.'
        );
    }

    private function enforceViaRedis(string $key): void
    {
        $redisKey = 'rate_limit:' . $key;
        $attempts = $this->redis->incr($redisKey);
        if ($attempts === 1) {
            $this->redis->expire($redisKey, $this->decaySeconds);
        }
        if ($attempts > $this->maxAttempts) {
            throw new TooManyRequestsException('Muitas tentativas. Tente novamente mais tarde.');
        }
    }

    private function enforceViaFile(string $key): void
    {
        $dir = dirname($this->fallbackFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $fp = fopen($this->fallbackFile, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo de rate limit.');
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('Não foi possível obter lock do rate limit.');
            }

            $raw = stream_get_contents($fp);
            $data = $raw ? (json_decode($raw, true) ?: []) : [];
            $now = time();

            foreach ($data as $k => $entry) {
                if (!isset($entry['window_start']) || ($now - (int)$entry['window_start']) > $this->decaySeconds) {
                    unset($data[$k]);
                }
            }

            if (!isset($data[$key])) {
                $data[$key] = ['count' => 0, 'window_start' => $now];
            }
            if (($now - (int)$data[$key]['window_start']) > $this->decaySeconds) {
                $data[$key] = ['count' => 0, 'window_start' => $now];
            }
            $data[$key]['count'] = (int)$data[$key]['count'] + 1;
            $exceeded = $data[$key]['count'] > $this->maxAttempts;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        if ($exceeded) {
            throw new TooManyRequestsException('Muitas tentativas. Tente novamente mais tarde.');
        }
    }
}

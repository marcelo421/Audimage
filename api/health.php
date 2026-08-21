<?php
declare(strict_types=1);

use App\Http\JsonResponder;
use App\Services\RateLimiter;

require_once __DIR__ . '/bootstrap.php';

/**
 * Infra healthcheck — intended for uptime monitors / load balancer health
 * probes / k8s liveness-readiness, not for end users.
 *
 * Returns 200 when Redis (the rate limiter's primary backend) is reachable.
 * Returns 503 when it isn't, so external monitoring can alert on this
 * independently of noticing a spike in 429s or grepping logs for
 * [RATE_LIMITER_FALLBACK_TRIGGERED].
 *
 * Deliberately returns no DB/infra details beyond redis up/down — this
 * endpoint is expected to be reachable without authentication.
 */
$redisUp = (new RateLimiter())->isBackedByRedis();

$allowFileFallback = getenv('RATE_LIMIT_ALLOW_FILE_FALLBACK');
$allowFileFallback = $allowFileFallback === false ? true : $allowFileFallback !== '0';

JsonResponder::respond(
    [
        'ok' => $redisUp,
        'redis' => $redisUp ? 'up' : 'down',
        'rate_limiter_fallback_mode' => $allowFileFallback ? 'file' : 'closed',
    ],
    $redisUp ? 200 : 503
);

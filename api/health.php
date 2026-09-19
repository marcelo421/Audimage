<?php
declare(strict_types=1);

use App\Http\JsonResponder;
use App\Services\RateLimiter;

require_once __DIR__ . '/bootstrap.php';

// Verifica se o serviço de rate limiting está disponível.
// Esta rota é usada por monitoramento externo para saber se a aplicação está viva.
$redisUp = (new RateLimiter())->isBackedByRedis();

// Se a configuração permitir, o sistema pode cair em fallback em arquivo em vez de falhar.
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

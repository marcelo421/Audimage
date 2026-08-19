<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Repository\UserRepository;
use App\Services\RateLimiter;
use App\Services\AuthService;
use App\Services\GoogleTokenVerifier;

$pdo = Connection::createFromEnv();
$rateLimiter = new RateLimiter();
$userRepository = new UserRepository($pdo);

$googleClientId = getenv('GOOGLE_CLIENT_ID') ?: '428028486316-ek5l780hfk56p8sekojmfbgutiu1gcjt.apps.googleusercontent.com';
$googleTokenVerifier = new GoogleTokenVerifier($googleClientId);

$authService = new AuthService($userRepository, $rateLimiter, $googleTokenVerifier);

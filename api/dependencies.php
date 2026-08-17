<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Repository\UserRepository;
use App\Repository\PresetRepository;
use App\Services\RateLimiter;
use App\Services\AuthService;
use App\Services\PresetService;
use App\Services\GoogleTokenVerifier;

$pdo = Connection::createFromEnv();
$rateLimiter = new RateLimiter();
$userRepository = new UserRepository($pdo);
$presetRepository = new PresetRepository($pdo);
$authService = new AuthService($userRepository, $rateLimiter);
$presetService = new PresetService($presetRepository);

$googleClientId = getenv('GOOGLE_CLIENT_ID') ?: '428028486316-ek5l780hfk56p8sekojmfbgutiu1gcjt.apps.googleusercontent.com';
$googleTokenVerifier = new GoogleTokenVerifier($googleClientId);

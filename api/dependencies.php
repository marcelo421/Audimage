<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Repository\UserRepository;
use App\Repository\PresetRepository;
use App\Services\RateLimiter;
use App\Services\AuthService;

$pdo = Connection::createFromEnv();
$rateLimiter = new RateLimiter();
$userRepository = new UserRepository($pdo);
$presetRepository = new PresetRepository($pdo);
$authService = new AuthService($userRepository, $rateLimiter);

<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Repository\UserRepository;
use App\Repository\EmailVerificationRepository;
use App\Repository\PasswordResetRepository;
use App\Services\RateLimiter;
use App\Services\AuthService;
use App\Services\GoogleTokenVerifier;
use App\Services\EmailVerificationService;
use App\Services\PasswordResetService;
use App\Mail\MailerFactory;

$pdo = Connection::createFromEnv();
$rateLimiter = new RateLimiter();
$userRepository = new UserRepository($pdo);
$emailVerificationRepository = new EmailVerificationRepository($pdo);
$passwordResetRepository = new PasswordResetRepository($pdo);

$googleClientId = getenv('GOOGLE_CLIENT_ID') ?: '428028486316-ek5l780hfk56p8sekojmfbgutiu1gcjt.apps.googleusercontent.com';
$googleTokenVerifier = new GoogleTokenVerifier($googleClientId);

$mailer = MailerFactory::createFromEnv();
$appUrl = getenv('APP_URL') ?: 'http://localhost:8000';

$emailVerificationService = new EmailVerificationService(
    $userRepository,
    $emailVerificationRepository,
    $mailer,
    $rateLimiter,
    $appUrl
);

$passwordResetService = new PasswordResetService(
    $userRepository,
    $passwordResetRepository,
    $mailer,
    $rateLimiter,
    $appUrl
);

$authService = new AuthService($userRepository, $rateLimiter, $googleTokenVerifier, $emailVerificationService);

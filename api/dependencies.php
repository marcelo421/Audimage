<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Repository\UserRepository;
use App\Repository\PresetRepository;
use App\Services\RateLimiter;
use App\Services\AuthService;
use App\Mail\MailerFactory;
use App\Repository\EmailVerificationRepository;
use App\Services\EmailVerificationService;

$pdo = Connection::createFromEnv();
$rateLimiter = new RateLimiter();
$userRepository = new UserRepository($pdo);
$presetRepository = new PresetRepository($pdo);
$authService = new AuthService($userRepository, $rateLimiter);

// Mailer and email verification service (used for sending verification emails)
$mailer = MailerFactory::createFromEnv();
$emailVerificationRepo = new EmailVerificationRepository($pdo);
$appUrl = (string)(getenv('APP_URL') ?: 'http://localhost/audimage');
$emailVerificationService = new EmailVerificationService(
	$userRepository,
	$emailVerificationRepo,
	$mailer,
	$rateLimiter,
	$appUrl
);

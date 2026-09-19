<?php
declare(strict_types=1);

// Este arquivo prepara todos os objetos de infraestrutura usados pelas APIs.
// Em linguagem simples: abre a conexão com o banco, cria repositórios e serviços.

use App\Database\Connection;
use App\Repository\UserRepository;
use App\Repository\PresetRepository;
use App\Repository\PasswordResetRepository;
use App\Services\RateLimiter;
use App\Services\AuthService;
use App\Mail\MailerFactory;
use App\Repository\EmailVerificationRepository;
use App\Services\EmailVerificationService;
use App\Services\PasswordResetService;

// Conexão com o banco de dados do projeto.
$pdo = Connection::createFromEnv();

// Limitar requisições repetidas para evitar abuso.
$rateLimiter = new RateLimiter();

// Repositórios: são as classes que falam com o banco.
$userRepository = new UserRepository($pdo);
$presetRepository = new PresetRepository($pdo);

// Serviço principal de autenticação.
$authService = new AuthService($userRepository, $rateLimiter);

// Configuração de envio de e-mails para confirmação e reset de senha.
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

// Repositório e serviço de recuperação de senha.
$passwordResetRepo = new PasswordResetRepository($pdo);
$passwordResetService = new PasswordResetService(
	$userRepository,
	$passwordResetRepo,
	$mailer,
	$rateLimiter,
	$appUrl
);

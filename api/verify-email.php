<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Esta rota é clicada a partir do e-mail de confirmação.
// Por isso ela não usa CSRF: não há sessão/app context para mandar cabeçalho CSRF.
// O token único, de alta entropia e expiração curta é a prova de intenção.
$token = $_GET['token'] ?? '';
$token = is_string($token) ? $token : '';

$verified = false;
if ($token !== '') {
    try {
        $verified = $emailVerificationService->verify($token);
    } catch (\Throwable $e) {
        error_log('verify-email: ' . $e->getMessage());
        $verified = false;
    }
}

// Redireciona de volta ao app com parâmetro "verified" para mostrar mensagem ao usuário.
$redirectTo = rtrim(getenv('APP_URL') ?: '', '/') . '/index.html?verified=' . ($verified ? '1' : '0');

// Se APP_URL não estiver configurado, usa caminho relativo para funcionar no ambiente local.
if (getenv('APP_URL') === false || getenv('APP_URL') === '') {
    $redirectTo = '../index.html?verified=' . ($verified ? '1' : '0');
}

header('Location: ' . $redirectTo, true, 302);
exit;

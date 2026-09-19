<?php
declare(strict_types=1);

use App\Exception\TooManyRequestsException;
use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Confirma a redefinição de senha usando o token recebido no link do e-mail.
// Aqui o usuário está enviando um formulário do próprio app, então CSRF continua obrigatório.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

try {
    $data = Request::getJsonBody();
    $token = trim((string)($data['token'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($token === '') {
        JsonResponder::respond(['ok' => false, 'message' => 'Token ausente.'], 400);
    }

    // Limita tentativas de confirmação para dificultar abuso automatizado.
    $rateLimiter->enforce('password-reset-confirm', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $result = $passwordResetService->resetPassword($token, $password);
    JsonResponder::respond($result, $result['ok'] ? 200 : 400);
} catch (\Throwable $e) {
    if ($e instanceof TooManyRequestsException) {
        JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], 429);
    }
    error_log($e->getMessage());
    JsonResponder::serverError();
}

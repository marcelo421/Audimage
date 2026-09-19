<?php
declare(strict_types=1);

use App\Exception\AuthException;
use App\Exception\TooManyRequestsException;
use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Endpoint de login.
// Só aceita POST e exige token CSRF válido para evitar que outra página force login.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

try {
    // Lê o JSON enviado pelo frontend e chama o serviço de autenticação.
    $data = Request::getJsonBody();
    $result = $authService->login(trim($data['user'] ?? ''), $data['pass'] ?? '');
    JsonResponder::respond($result->toArray());
} catch (TooManyRequestsException $e) {
    // Excesso de tentativas: responde 429.
    JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], 429);
} catch (AuthException $e) {
    // Erros de autenticação já vêm com status HTTP correto. A API só retorna a mensagem.
    JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
